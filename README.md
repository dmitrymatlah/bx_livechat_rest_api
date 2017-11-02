bitrix24-livechat-restapi
================
[![Latest Stable Version](https://poser.pugx.org/adv/bitrix24-livechat-restapi/v/stable)](https://packagist.org/packages/adv/bitrix24-livechat-restapi)


PHP Library for the Bitrix24 LIVECHAT REST API


## Requirements
- php: >=5.6
- ext-json: *

## Installation ##
Add `"adv/bitrix24-livechat-restapi": "dev-master"` to `composer.json` of your application. Or clone repo to your project.


-make directory /pub/api/
-copy file adv/bitrix24-livechat-restapi/dist/router.php to  /pub/api/router.php
-add condition to /urlrewrite.php of your Bitrix24 project 
`    array(
        "CONDITION" => "#^/pub/api/v1/chat/([\\.\\-0-9a-zA-Z]+)/([\\.\\-0-9a-zA-Z]+)(/?)([^/]*)(/?)([^/]*)#",
        "RULE" => "",
        "ID" => "",
        "PATH" => "/pub/api/router.php",
    ),
- open new chat using http(s)://<DOMAIN_NAME>/pub/api/v1/chat/<LIVE_CHAT_ALIAS>/open request

- use https://app.swaggerhub.com/apis/dmitrymatlah/bitrix24-livechat-restapi/ to find out other endpoins description

## Submitting bugs and feature requests

Bugs and feature request are tracked on [GitHub](https://github.com/dmitrymatlah/bitrix24-livechat-restapi/issues)

## License

bitrix24-livechat-restapi has proprietary license

## Author

Dmitry Matlah - <dmitrymatlah@gmail.com> 

