SpectroCoin OpenCart Bitcoin Payment Extension
---------------

This is [SpectroCoi Bitcoin Payment Module for OpenCart](https://spectrocoin.com/en/plugins/accept-bitcoin-opencart.html). This extenstion allows to easily accept bitcoins (and other cryptocurrencies such as DASH) at your OpenCart website. You can view [a tutorial how to integrate bitcoin payments for OpenCart](https://www.youtube.com/watch?v=pMKTjTI_UGk).

To succesfully use this plugin, you have to have a SpectroCoin Bitcoin wallet. You can get it [here](https://spectrocoin.com/en/bitcoin-wallet.html). Also you have to create a merchant project to get merchant and project IDs, to do so create a new merchant project [here](https://spectrocoin.com/en/merchant/api/create.html).

**INSTALLATION**

1. Upload content to your OpenCart directory.
2. Generate private and public keys<br />
	i. Automatically<br />
	
	Go to [SpectroCoin](https://spectrocoin.com/) -> [Project list](https://spectrocoin.com/en/merchant/api/list.html)
	click on your project, then select "Edit Project and then click "Generate" (next to Public key field), as a result you will get an automatically generated private key, download and save it. The matching Public key will be generated automatically and added to your project.
	
	ii. Manually<br />
    	
	Private key:
    ```shell
    # generate a 2048-bit RSA private key
    openssl genrsa -out "C:\private" 2048
	
    ```
    <br />
    	Public key:
    ```shell
    # output public key portion in PEM format
    openssl rsa -in "C:\private" -pubout -outform PEM -out "C:\public"
    ```
	<br />

	Do not forget to add new Public key to your project by pasting it into Public key field under "Edit project" section. 
    
**CONFIGURATION**

3. Go to Admin -> Extensions -> Payments and enable SpectroCoin module
4. Enter your Merchant Id, Project Id, Private key.

**INFORMATION**

This plugin has been developed by SpectroCoin.com
If you need any further support regarding our services you can contact us via:<br />
E-mail: [info@spectrocoin.com](mailto:info@spectrocoin.com)<br />
Phone: +442037697306<br />
Skype: [spectrocoin_merchant](skype:spectrocoin_merchant)<br />
Web: [https://spectrocoin.com](https://spectrocoin.com)<br />
Twitter: [@spectrocoin](https://twitter.com/spectrocoin)<br />
Facebook: [SpectroCoin](https://www.facebook.com/spectrocoin)<br />
