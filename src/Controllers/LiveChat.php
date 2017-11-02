<?php
/**
 * Created by PhpStorm.
 * User: mclaod
 * Date: 30.10.17
 * Time: 19:19
 */

namespace BxLivechatRestApi\Controllers;

use Silex\Application;
use Silex\Route;
use Silex\Api\ControllerProviderInterface;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class LiveChat implements ControllerProviderInterface
{
    /**
     * @var array
     */
    protected $response = [];

    public function connect(Application $app)
    {

        $method = new ControllerCollection(new Route());

        /*GET*/


        /*Получение списка сообщений*/
        $method->get(
            '{alias}/{chatHash}/list',
            function (Request $request, $alias, $chatHash) use ($app) {

                $liveChat = self::getChatEntity($app, $alias, $chatHash);
                /*Параметры запроса списка*/
                $limit = (int)$request->get('limit') ?: 10;
                $offset = (int)$request->get('offset') ?: 0;
                /*Запрос списка*/
                $chatItems = $liveChat->getList($limit, $offset);
                $this->response = [
                    'LIVECHAT_HASH' => $liveChat->getChatHash(),
                    'LIST'          => $chatItems,
                    'LIMIT'         => $limit,
                    'OFFSET'        => $offset,
                ];

                return $this->getResponse();

            }
        );

        /*POST*/

        /*Создание сессии чата*/
        $method->post(
            '{alias}/open/',
            function ($alias) use ($app) {
                $liveChat = self::getChatEntity($app, $alias, '', false);
                $this->response = [
                    'LIVECHAT_HASH' => $liveChat->getChatHash(),
                ];

                return $this->getResponse();

            }
        );

        /*Добавление сообщения*/
        $method->post(
            '{alias}/{chatHash}/add_message',
            function (Request $request, $alias, $chatHash) use ($app) {
                $liveChat = self::getChatEntity($app, $alias, $chatHash);
                $this->response = $liveChat->addMessage($request->get('message'));
                $this->response['LIVECHAT_HASH'] = $liveChat->getChatHash();

                return $this->getResponse();

            }
        );


        /*PUT*/

        /*Событие: Начало печати ответа*/
        $method->put(
            '{alias}/{chatHash}/start_writing',
            function (Request $request, $alias, $chatHash) use ($app) {
                $liveChat = self::getChatEntity($app, $alias, $chatHash);
                $this->response = [
                    'STATUS' => $liveChat->startWritingEvent($request->get('message'))
                        ? 'OK'
                        : 'ERROR',
                    'LIVECHAT_HASH' => $liveChat->getChatHash()
                ];

                return $this->getResponse();
            }
        );
        /*Событие: Сообщение прочитано*/
        $method->put(
            '{alias}/{chatHash}/read_message',
            function (Request $request, $alias, $chatHash) use ($app) {
                $liveChat = self::getChatEntity($app, $alias, $chatHash);
                $this->response = [
                    'STATUS' => $liveChat->readMessageEvent($request->get('last_id'))
                        ? 'OK'
                        : 'ERROR',
                    'LIVECHAT_HASH' => $liveChat->getChatHash()
                ];

                return $this->getResponse();
            }
        );
        /*Событие: Сброс метки "сообщение прочитано"*/
        $method->put(
            '{alias}/{chatHash}/unread_message',
            function (Request $request, $alias, $chatHash) use ($app) {
                $liveChat = self::getChatEntity($app, $alias, $chatHash);
                $this->response = [
                    'STATUS' => $liveChat->unReadMessageEvent($request->get('last_id'))
                        ? 'OK'
                        : 'ERROR',
                    'LIVECHAT_HASH' => $liveChat->getChatHash()
                ];

                return $this->getResponse();
            }
        );

        return $method;
    }

    /**
     * @param Application $app
     * @param $alias
     * @param $chatHash
     * @param bool $checkIsOpened
     *
     * @return \Adv\Zoomir\ChatApi\Entities\Chat
     */
    protected static function getChatEntity(Application $app, $alias, $chatHash, $checkIsOpened = true)
    {
        /*Инциализация чата по алиасу ОЛ  и хэшу чата
        Для открытия новой сессии передаем $checkIsOpened = false
        */
        $app['chat.alias'] = $alias;
        $app['chat.hash'] = $chatHash;
        $app['chat.checkIsOpened'] = (bool) $checkIsOpened;

        return $app['chatEntity'];
    }

    /**
     * @return string
     */
    protected function getResponse()
    {
        return new JsonResponse($this->response);
    }
}