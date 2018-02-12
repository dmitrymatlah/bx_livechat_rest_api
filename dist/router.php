<?php

use BxLivechatRestApi\Controllers\LiveChatController;
use BxLivechatRestApi\Entities\Chat;
use Symfony\Component\HttpFoundation\JsonResponse;
use Bitrix\Main\Loader;

$widgetUserLangPath = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/im/lang/';
if (
    isset($_GET['widget_user_lang'])
    && preg_match("/^[a-z]{2,2}$/", $_GET['widget_user_lang'])
    && strlen($_GET['widget_user_lang']) == 2
    && @is_dir($widgetUserLangPath . $_GET['widget_user_lang'])
) {
    setcookie("WIDGET_USER_LANG", $_GET['widget_user_lang'], time() + 9999999, "/");
    define("LANGUAGE_ID", $_GET['widget_user_lang']);
} elseif (
    isset($_COOKIE['WIDGET_USER_LANG'])
    && preg_match("/^[a-z]{2,2}$/", $_COOKIE['WIDGET_USER_LANG'])
    && strlen($_COOKIE['WIDGET_USER_LANG']) == 2
    && @is_dir($widgetUserLangPath . $_COOKIE['WIDGET_USER_LANG'])
) {
    define("LANGUAGE_ID", $_COOKIE['WIDGET_USER_LANG']);
}

if (!defined('IM_AJAX_INIT')) {
    define("IM_AJAX_INIT", true);
    define("PUBLIC_AJAX_MODE", true);
    define("NO_KEEP_STATISTIC", "Y");
    define("NO_AGENT_STATISTIC", "Y");
    define("NOT_CHECK_PERMISSIONS", true);
    define("DisableEventsCheck", true);
    define("STOP_STATISTICS", true);
    require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
}

$app = new Silex\Application();
$app['debug'] = true;
$app['config.basePath'] = '/pub/api/v1/chat/';
/*При попытке создать сообщение,
либо получить список сообщений и т.д.
будет выполнена проверка существования сессии чата по хэшу
и если такой нет, будет выброшено исключение
*/
$app['chat.checkIsOpened'] = false;

$app['chatEntity'] = function($app) {
    return new Chat($app['chat.alias'], $app['chat.hash'], $app['chat.checkIsOpened']);
};

$app->before(function () {


    if (!Loader::includeModule('im')) {
        throw new \Exception('Модуль im не установлен', 503);
    }
    if (!Loader::includeModule('imopenlines')) {
        throw new \Exception('Модуль imopenlines не установлен', 503);
    }
    if (!Loader::includeModule('disk')) {
        throw new \Exception('Модуль disk не установлен', 503);
    }

});

$app->mount($app['config.basePath'], new LiveChat());

$app->error(function(\Exception $e){
    $defCode = $e->getCode() ?: 500;
    if($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException){
        $defCode = $e->getStatusCode();
    }
    return new JsonResponse(['ERROR' => $e->getMessage()], $defCode);
});
$app->run();

$app->after(function(){
    \CMain::FinalActions();
    die();
});


