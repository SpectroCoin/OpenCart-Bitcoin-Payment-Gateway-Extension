# SpectroCoin Opencart Crypto Payment Plugin

Integrate cryptocurrency payments seamlessly into your Opencart store with the [SpectroCoin Crypto Payment Plugin](https://spectrocoin.com/plugins/accept-bitcoin-opencart.html). This extension facilitates the acceptance of a variety of cryptocurrencies, enhancing payment options for your customers. Easily configure and implement secure transactions for a streamlined payment process on your Opencart website.

## Installation

1. Download latest plugin release from [github](https://github.com/SpectroCoin/OpenCart-Bitcoin-Payment-Gateway-Extension). The downloaded zip folder has to be named "spectrocoin.ocmod.zip".
2. Go to Opencart dashboard and navigate to Extensions -> Installer. Upload the "spectrocoin.ocmod.zip" file.
3. Go to Extensions -> Extensions. Choose Payments from the dropdown. Find SpectroCoin and click Install.
4. Click Edit to configure the plugin settings.

## Setting up

1. **[Sign up](https://auth.spectrocoin.com/signup)** for a SpectroCoin Account.
2. **[Log in](https://auth.spectrocoin.com/login)** to your SpectroCoin account.
3. On the dashboard, locate the **[Business](https://spectrocoin.com/en/merchants/projects)** tab and click on it.
4. Click on **[New project](https://spectrocoin.com/en/merchants/projects/new)**.
5. Fill in the project details and select desired settings (settings can be changed).
6. Click "Submit".
7. Copy and paste the "Project id".
8. Click on the user icon in the top right and navigate to **[Settings](https://test.spectrocoin.com/en/settings/)**. Then click on **[API](https://test.spectrocoin.com/en/settings/api)** and choose **[Create New API](https://test.spectrocoin.com/en/settings/api/create)**.
9. Add "API name", in scope groups select "View merchant preorders", "Create merchant preorders", "View merchant orders", "Create merchant orders", "Cancel merchant orders" and click "Create API".
10. Copy and store "Client id" and "Client secret". Save the settings.

**Note:** Keep in mind that if you want to use the business services of SpectroCoin, your account has to be verified.

## Make it work on localhost

We gently suggest trying out the plugin in a server environment, as it will not be capable of receiving callbacks from SpectroCoin if it will be hosted on localhost. To successfully create and order on localhost for testing purposes, <b>change these 3 lines in <em>SCMechantClient.php createOrder() function</em></b>:

`'callbackUrl' => $request->getCallbackUrl(),
'successUrl' => $request->getSuccessUrl(),
'failureUrl' => $request->getFailureUrl()`

<b>To</b>

`'callbackUrl' => 'http://localhost.com',
'successUrl' => 'http://localhost.com',
'failureUrl' => 'http://localhost.com'`

Adjust it appropriately if your local environment URL differs.
Don't forget to change it back when migrating website to public.

## Changelog

### 1.0.0 ()

_Updated_: Order creation API endpoint has been updated for enhanced performance and security.

_Removed_: Private key functionality and merchant ID requirement have been removed to streamline integration.

_Added_: OAuth functionality introduced for authentication, requiring Client ID and Client Secret for secure API access.

_Added:_ Enhanced the style of the admin's payment settings window to match the design of SpectroCoin.com, providing a more cohesive user experience.

Migrated: Since HTTPful is no longer maintained, we migrated to GuzzleHttp. In this case /vendor directory was added which contains GuzzleHttp dependencies.

Added: Settings field sanitization.

Added: Settings field validation. In this case we minimized possible error count during checkout, SpectroCoin won't appear in checkout until settings validation is passed.

_Added_: "spectrocoin\_" prefix to functiton names.

_Added_: "SpectroCoin\_" prefix to class names.

_Added_: Validation and Sanitization when request payload is created.

_Added_: Validation and Sanitization when callback is received.

_Added_: Components class "SpectroCoin_FormattingUtil" changed to "SpectroCoin_Utilities"

_Added_: Appropriate error logging to Opencart admin.

_Added_: API error form.

_Optimised_: Removed the The whole $\_REQUEST stack processing. Now only needed callback keys is being processed.

## Information

This client has been developed by SpectroCoin.com If you need any further support regarding our services you can contact us via:

E-mail: merchant@spectrocoin.com </br>
Skype: spectrocoin_merchant </br>
[Web](https://spectrocoin.com) </br>
[Twitter](https://twitter.com/spectrocoin) </br>
[Facebook](https://www.facebook.com/spectrocoin/)<br />
