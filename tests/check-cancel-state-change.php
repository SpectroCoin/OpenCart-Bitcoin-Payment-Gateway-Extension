<?php

/**
 * Invariant tests for the payer-facing cancel endpoint.
 *
 * `extension/spectrocoin/payment/cancel` is the failureUrl this extension hands
 * to SpectroCoin, i.e. a landing page for the payer's browser. It is
 * presentation only and never writes order history. Order status has a single
 * owner, the callback controller, which establishes that the request came from
 * SpectroCoin before writing.
 *
 * These tests pin that separation so it is not eroded by a later change.
 *
 * Standalone by design: the extension ships no PHPUnit setup, and an OpenCart
 * bootstrap is not needed to observe which methods write.
 *
 * Run:  php tests/check-cancel-state-change.php
 */

// ---------------------------------------------------------------------------
// Stubs. Defined before the controller is loaded.
// ---------------------------------------------------------------------------

namespace Opencart\System\Engine {

    class Controller
    {
        public $registry = [];

        /** Names resolved through the registry, recorded so tests can assert. */
        public static $accessed = [];

        public function __get($key)
        {
            self::$accessed[] = $key;
            return $this->registry[$key] ?? null;
        }
    }
}

namespace {

    /** Records every order-history write attempt. */
    class SpyCheckoutOrderModel
    {
        public $addHistoryCalls = [];
        public $getOrderCalls = [];

        public function addHistory($order_id, $status_id)
        {
            $this->addHistoryCalls[] = [$order_id, $status_id];
        }

        public function getOrder($order_id)
        {
            $this->getOrderCalls[] = $order_id;
            return ['order_id' => $order_id, 'total' => '10.00'];
        }
    }

    class SpyAccountOrderModel
    {
        public $getOrdersCalls = 0;

        public function getOrders()
        {
            $this->getOrdersCalls++;
            return [['order_id' => 4242]];
        }
    }

    class SpyLoader
    {
        public $models = [];
        public $views = [];

        public function model($route) { $this->models[] = $route; }

        public function view($template, $data = [])
        {
            $this->views[] = $template;
            return "rendered:{$template}";
        }
    }

    class SpyLog
    {
        public $lines = [];
        public function write($m) { $this->lines[] = $m; }
    }

    class SpyLanguage
    {
        public $loaded = [];
        public function load($f) { $this->loaded[] = $f; }
        public function get($k) { return "lang:{$k}"; }
    }

    class SpyUrl
    {
        public function link($route, $args = '', $secure = false) { return "/index.php?route={$route}"; }
    }

    class SpyResponse
    {
        public $output = null;
        public function setOutput($o) { $this->output = $o; }
    }

    class SpySession
    {
        public $data = [];
    }

    class SpyRequest
    {
        public $get = [];
        public $server = [];
    }

    // -----------------------------------------------------------------------

    class TestRunner
    {
        private $failures = [];
        private $passed = 0;
        private $failed = 0;

        public function assertTrue($cond, $message)
        {
            if (!$cond) { $this->failures[] = $message; }
        }

        public function assertSame($expected, $actual, $message)
        {
            if ($expected !== $actual) {
                $this->failures[] = $message
                    . ' (expected ' . var_export($expected, true)
                    . ', got ' . var_export($actual, true) . ')';
            }
        }

        public function run($name, callable $test)
        {
            $this->failures = [];
            try {
                $test($this);
            } catch (\Throwable $e) {
                $this->failures[] = 'threw ' . get_class($e) . ': ' . $e->getMessage();
            }
            if (empty($this->failures)) {
                $this->passed++;
                echo "  PASS  {$name}\n";
            } else {
                $this->failed++;
                echo "  FAIL  {$name}\n";
                foreach ($this->failures as $f) { echo "          {$f}\n"; }
            }
        }

        public function summary()
        {
            echo "\n{$this->passed} passed, {$this->failed} failed\n";
            return $this->failed === 0 ? 0 : 1;
        }
    }

    // -----------------------------------------------------------------------

    define('HTTP_SERVER', 'http://shop.example/');
    define('HTTPS_SERVER', 'https://shop.example/');
    // The enum refuses to load outside an OpenCart request.
    define('DIR_APPLICATION', __DIR__ . '/../catalog/');

    $cancelFile = __DIR__ . '/../catalog/controller/payment/cancel.php';
    require_once $cancelFile;
    $cancelSource = file_get_contents($cancelFile);
    $callbackSource = file_get_contents(__DIR__ . '/../catalog/controller/payment/callback.php');

    $t = new TestRunner();

    echo "SpectroCoin OpenCart — payer-cancel state-change regression\n\n";

    /**
     * @return array{0: \Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\Cancel, 1: SpyCheckoutOrderModel, 2: SpyAccountOrderModel}
     */
    $makeController = function (array $get, array $sessionData) {
        $checkoutModel = new SpyCheckoutOrderModel();
        $accountModel = new SpyAccountOrderModel();

        $session = new SpySession();
        $session->data = $sessionData;

        $request = new SpyRequest();
        $request->get = $get;
        $request->server = ['HTTPS' => 'on'];

        $c = new \Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\Cancel();
        $c->registry = [
            'load' => new SpyLoader(),
            'log' => new SpyLog(),
            'language' => new SpyLanguage(),
            'url' => new SpyUrl(),
            'response' => new SpyResponse(),
            'session' => $session,
            'request' => $request,
            'model_checkout_order' => $checkoutModel,
            'model_account_order' => $accountModel,
        ];
        \Opencart\System\Engine\Controller::$accessed = [];

        return [$c, $checkoutModel, $accountModel];
    };

    // --- The cancel endpoint must not write ------------------------------

    $t->run('cancel does not act on an order id from the query string', function ($t) use ($makeController) {
        [$c, $checkout, $account] = $makeController(['order_id' => '4242'], []);
        $c->index();

        $t->assertSame(0, count($checkout->addHistoryCalls),
            'addHistory() must not be called from the cancel landing page');
        $t->assertSame(0, count($checkout->getOrderCalls),
            'getOrder() must not be called from the cancel landing page');
    });

    $t->run('cancel writes nothing across a range of order ids', function ($t) use ($makeController) {
        foreach (['1', '2', '999999', '0', '-1', 'abc'] as $id) {
            [$c, $checkout] = $makeController(['order_id' => $id], []);
            $c->index();
            $t->assertSame(0, count($checkout->addHistoryCalls),
                "addHistory() must not be called for order_id={$id}");
        }
    });

    $t->run('cancel writes nothing even with a session order id', function ($t) use ($makeController) {
        [$c, $checkout] = $makeController([], ['order_id' => 77]);
        $c->index();

        $t->assertSame(0, count($checkout->addHistoryCalls),
            'addHistory() must not be called even for the session order of the payer');
    });

    $t->run('cancel never falls back to the account most recent order', function ($t) use ($makeController) {
        [$c, $checkout, $account] = $makeController([], []);
        $c->index();

        $t->assertSame(0, $account->getOrdersCalls,
            'the account-order fallback must be gone: it acted on an unrelated order');
        $t->assertSame(0, count($checkout->addHistoryCalls),
            'addHistory() must not be called');
    });

    // --- The page must still work -------------------------------------------

    $t->run('cancel still renders the failure page', function ($t) use ($makeController) {
        [$c] = $makeController(['order_id' => '4242'], []);
        $c->index();

        $response = $c->registry['response'];
        $t->assertSame('rendered:extension/spectrocoin/payment/spectrocoin_failure',
            $response->output, 'the failure page must still be rendered');
    });

    // --- Static guards against reintroduction --------------------------------

    $t->run('cancel.php contains no order write and no id fallbacks', function ($t) use ($cancelSource) {
        $t->assertTrue(strpos($cancelSource, 'addHistory') === false,
            'cancel.php must not call addHistory()');
        $t->assertTrue(strpos($cancelSource, "request->get['order_id']") === false,
            'cancel.php must not read order_id from the query string');
        $t->assertTrue(strpos($cancelSource, 'model_account_order') === false,
            'cancel.php must not fall back to the account order list');
    });

    // --- The authenticated path must remain intact ---------------------------

    $t->run('callback.php still owns order status', function ($t) use ($callbackSource) {
        $t->assertTrue(strpos($callbackSource, 'addHistory($order_id, 7)') !== false,
            'callback must still mark FAILED orders canceled');
        $t->assertTrue(strpos($callbackSource, 'addHistory($order_id, 14)') !== false,
            'callback must still mark EXPIRED orders expired');
        $t->assertTrue(strpos($callbackSource, 'addHistory($order_id, 15)') !== false,
            'callback must still mark PAID orders processed');
        $t->assertTrue(strpos($callbackSource, 'OrderStatus::CANCELLED') !== false,
            'callback must handle the CANCELLED status the API sends when an order is cancelled');
        $t->assertTrue(strpos($callbackSource, 'isInformational()') !== false,
            'callback must skip status changes for informational statuses');
    });

    $t->run('the status enum accepts every status the API can send', function ($t) {
        require_once __DIR__ . '/../system/library/spectrocoin/Enum/OrderStatus.php';
        $enum = \Opencart\Catalog\Controller\Extension\Spectrocoin\Payment\Enum\OrderStatus::class;
        $wire = [
            'NEW' => 1, 'PENDING' => 2, 'PAID' => 3, 'FAILED' => 4, 'EXPIRED' => 5,
            'LATE_CRYPTO_PAYMENT' => 10, 'PARTIAL_PAYMENT' => 11, 'UNDERPAID' => 12,
            'CANCELLED' => 13, 'INVALID_PAYMENT' => 14, 'PROCESSING_REFUND' => 17,
            'REFUNDED' => 18, 'REJECTED_REFUND' => 19,
            'PENDING_LATE_CRYPTO_PAYMENT' => 20, 'REJECTED' => 21,
        ];
        $cancellations = ['FAILED', 'CANCELLED', 'REJECTED', 'INVALID_PAYMENT'];
        $informational = ['PARTIAL_PAYMENT', 'UNDERPAID', 'LATE_CRYPTO_PAYMENT',
                          'PENDING_LATE_CRYPTO_PAYMENT', 'PROCESSING_REFUND',
                          'REFUNDED', 'REJECTED_REFUND'];

        foreach ($wire as $name => $code) {
            $t->assertSame($name, $enum::normalize($name)->value,
                "normalize() must accept the {$name} status");
            $t->assertSame($name, $enum::normalize($code)->value,
                "normalize() must map legacy code {$code} to {$name}");
            $t->assertSame(in_array($name, $cancellations, true),
                $enum::normalize($name)->isCancellation(),
                "{$name}: isCancellation() classification");
            $t->assertSame(in_array($name, $informational, true),
                $enum::normalize($name)->isInformational(),
                "{$name}: isInformational() classification");
        }

        $threw = false;
        try { $enum::normalize('SOMETHING_NEW'); }
        catch (\InvalidArgumentException $e) { $threw = true; }
        $t->assertTrue($threw, 'an out-of-contract status must still be rejected');
    });

    exit($t->summary());
}
