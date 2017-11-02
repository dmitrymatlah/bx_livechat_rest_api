<?php
/**
 * Created by PhpStorm.
 * User: mclaod
 * Date: 25.10.17
 * Time: 14:04
 */

namespace BxLivechatRestApi\Entities;

use \Bitrix\Main\Localization\Loc;
use \Bitrix\Main\Loader;
use \Bitrix\ImOpenLines\LiveChat as BitrixLiveChat;

class Chat extends BitrixLiveChat
{
    private $aliasData;

    private $configId;

    private $config = null;

    private $sessionId = null;

    private $temporary = [];

    private $userId = null;

    private $chat = null;

    public function __construct($chatAlias, $chatHash = '', $checkIsOpened = true)
    {
        self::checkModules();

        $this->aliasData = \Bitrix\Im\Alias::get($chatAlias);
        if ($this->aliasData['ENTITY_TYPE'] == \Bitrix\Im\Alias::ENTITY_TYPE_OPEN_LINE
            && \IsModuleInstalled('imopenlines')) {
            $this->configId = $this->aliasData['ENTITY_ID'];
        }
        if (!$this->configId) {
            throw new \Exception('Не верное имя открытой линии', 400);
        }

        $configManager = new \Bitrix\ImOpenLines\Config();
        $this->config = $configManager->get($this->configId, true, false);

        parent::__construct($this->config);
        /*Если требуется, чтобы сессия уже была открыта,
        проверяем наличие по хэшу*/
        if ($checkIsOpened) {
            self::checkChatHash($chatHash);
        }

        $this->openSession($chatHash);

    }

    public static function isValidChatHash($liveChatHash)
    {
        if (!preg_match("/^[a-fA-F0-9]{32}$/i", $liveChatHash)) {
            return false;
        }

        $orm = \Bitrix\Main\UserTable::getList(
            [
                'filter' => [
                    '=EXTERNAL_AUTH_ID' => self::EXTERNAL_AUTH_ID,
                    '=XML_ID'           => 'livechat|' . $liveChatHash,
                ],
                'limit'  => 1,
            ]
        );

        return (bool)$orm->fetch();
    }

    /**
     * @param $liveChatHash
     *
     * @return bool
     * @throws \Exception
     */
    public static function checkChatHash($liveChatHash)
    {
        if (!self::isValidChatHash($liveChatHash)) {
            throw new \Exception('Чат не найден', 404);
        }

        return true;
    }

    /**
     * Открываем сессию открытой линии
     *
     * @param $liveChatHash
     *
     * @return null|string
     */
    public function openSession($liveChatHash = '')
    {

        $context = \Bitrix\Main\Application::getInstance()->getContext();
        $request = $context->getRequest();

        if (preg_match("/^[a-fA-F0-9]{32}$/i", $liveChatHash)) {
            $this->sessionId = $liveChatHash;
        } else {
            require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/classes/general/update_client.php");
            $licence = md5("BITRIX" . \CUpdateClient::GetLicenseKey() . "LICENCE");

            $this->sessionId = md5(time() . bitrix_sessid() . $licence);
        }

        $_SESSION['LIVECHAT_HASH'] = $this->sessionId;

        $this->userId = $this->getGuestUser();

        global $USER;
        if (!$USER->IsAuthorized() || $USER->GetID() != $this->userId) {
            $USER->Authorize($this->userId, false, true, 'public');
        }
        $this->getChatForUser();

        if (!is_array($this->chat)) {
            throw new \Exception('Не корректные данные чата', 417);
        }

        return $this->sessionId;
    }

    /**
     * @return string[32]|null
     */
    public function getChatHash()
    {
        return $this->sessionId;
    }

    private function getGuestUser($userId = null)
    {
        $xmlId = $this->sessionId;

        if (isset($this->temporary['USER_NAME']) && $this->temporary['USER_NAME']) {
            $userName = $this->temporary['USER_NAME'];
            $userLastName = isset($this->temporary['USER_LAST_NAME']) ? $this->temporary['USER_LAST_NAME'] : '';
        } else {
            $userName = '';
            $userLastName = self::getDefaultGuestName();
        }
        $userEmail = isset($this->temporary['USER_EMAIL']) ? $this->temporary['USER_EMAIL'] : '';
        $userWebsite = isset($this->temporary['USER_PERSONAL_WWW']) ? $this->temporary['USER_PERSONAL_WWW'] : '';
        $userGender = '';
        $userAvatar = isset($this->temporary['USER_AVATAR']) ? self::uploadAvatar($this->temporary['USER_AVATAR']) : '';
        $userWorkPosition = '';

        if ($userId && \Bitrix\Im\User::getInstance($userId)->isExists()) {
            if (\Bitrix\Im\User::getInstance($userId)->isConnector()) {
                return $userId;
            }
            $userData = \Bitrix\Im\User::getInstance($userId);
            $xmlId = $userData->getId();
            $userName = $userData->getName(false);
            $userLastName = $userData->getLastName(false);
            $userGender = $userData->getGender();
            $userWebsite = $userData->getWebsite();
            $userWorkPosition = $userData->getWorkPosition();
            $userAvatar = $userData->getAvatarId();
            $userEmail = $userData->getEmail();
            if ($userAvatar) {
                $userAvatar = \CFile::MakeFileArray($userAvatar);
            }
        }

        global $USER;
        /*if ($USER->IsAuthorized())
        {
            $orm = \Bitrix\Main\UserTable::getList(array(
                                                       'filter' => array('=ID' => $USER->GetId())
                                                   ));
        }
        else
        {*/
        $orm = \Bitrix\Main\UserTable::getList(
            [
                'filter' => [
                    '=EXTERNAL_AUTH_ID' => self::EXTERNAL_AUTH_ID,
                    '=XML_ID'           => 'livechat|' . $xmlId,
                ],
                'limit'  => 1,
            ]
        );
        // }

        if ($userFields = $orm->fetch()) {
            $userId = $userFields['ID'];

            $updateFields = [];
            if ($userWebsite && $userWebsite != $userFields['PERSONAL_WWW']) {
                $updateFields['PERSONAL_WWW'] = $userWebsite;
            }

            if (!empty($updateFields)) {
                $cUser = new \CUser;
                $cUser->Update($userId, $updateFields);
            }
        } else {
            $cUser = new \CUser;
            $fields['LOGIN'] = self::MODULE_ID . '_' . rand(1000, 9999) . randString(5);
            $fields['NAME'] = $userName;
            $fields['LAST_NAME'] = $userLastName;
            if ($userAvatar) {
                $fields['PERSONAL_PHOTO'] = $userAvatar;
            }
            if ($userEmail) {
                $fields['EMAIL'] = $userEmail;
            }
            if ($userWebsite) {
                $fields['PERSONAL_WWW'] = $userWebsite;
            }
            $fields['PERSONAL_GENDER'] = $userGender;
            $fields['WORK_POSITION'] = $userWorkPosition;
            $fields['PASSWORD'] = md5($fields['LOGIN'] . '|' . rand(1000, 9999) . '|' . time());
            $fields['CONFIRM_PASSWORD'] = $fields['PASSWORD'];
            $fields['EXTERNAL_AUTH_ID'] = self::EXTERNAL_AUTH_ID;
            $fields['XML_ID'] = 'livechat|' . $xmlId;
            $fields['ACTIVE'] = 'Y';

            $userId = $cUser->Add($fields);
        }

        return $userId;
    }

    private function getChatForUser()
    {
        $orm = \Bitrix\Im\Model\ChatTable::getList(
            [
                'filter' => [
                    '=ENTITY_TYPE' => 'LIVECHAT',
                    '=ENTITY_ID'   => $this->config['ID'] . '|' . $this->userId,
                ],
                'limit'  => 1,
            ]
        );
        if ($chat = $orm->fetch()) {
            if (isset($this->temporary['FIRST_MESSAGE']) && $chat['DESCRIPTION'] != $this->temporary['FIRST_MESSAGE']) {
                $chatManager = new \CIMChat(0);
                $chatManager->SetDescription($chat['ID'], $this->temporary['FIRST_MESSAGE']);
                $chat['DESCRIPTION'] = $this->temporary['FIRST_MESSAGE'];
            }
            $this->chat = $chat;

            $ar = \CIMChat::GetRelationById($this->chat['ID']);
            if (!isset($ar[$this->userId])) {
                $chatManager = new \CIMChat(0);
                $chatManager->AddUser($this->chat['ID'], $this->userId, false, true); // TODO security context
            }

            return $this->chat;
        }

        $avatarId = 0;
        $userName = '';
        $chatColorCode = '';
        $addChat['USERS'] = false;
        if ($this->userId) {
            $orm = \Bitrix\Main\UserTable::getById($this->userId);
            if ($user = $orm->fetch()) {
                if ($user['PERSONAL_PHOTO'] > 0) {
                    $avatarId = \CFile::CopyFile($user['PERSONAL_PHOTO']);
                }
                $addChat['USERS'] = [$this->userId];

                $userName = \Bitrix\Im\User::getInstance($this->userId)->getFullName(false);
                $chatColorCode = \Bitrix\Im\Color::getCodeByNumber($this->userId);
                if (\Bitrix\Im\User::getInstance($this->userId)->getGender() == 'M') {
                    $replaceColor = \Bitrix\Im\Color::getReplaceColors();
                    if (isset($replaceColor[$chatColorCode])) {
                        $chatColorCode = $replaceColor[$chatColorCode];
                    }
                }
            }
        }

        if (!$userName) {
            $result = \Bitrix\ImOpenLines\Chat::getGuestName();
            $userName = $result['USER_NAME'];
            $chatColorCode = $result['USER_COLOR'];
        }

        $addChat['TITLE'] = Loc::getMessage(
            'IMOL_LC_CHAT_NAME',
            ["#USER_NAME#" => $userName, "#LINE_NAME#" => $this->config['LINE_NAME']]
        );

        $addChat['TYPE'] = IM_MESSAGE_CHAT;
        $addChat['COLOR'] = $chatColorCode;
        $addChat['AVATAR_ID'] = $avatarId;
        $addChat['ENTITY_TYPE'] = 'LIVECHAT';
        $addChat['ENTITY_ID'] = $this->config['ID'] . '|' . $this->userId;
        $addChat['SKIP_ADD_MESSAGE'] = 'Y';
        $addChat['AUTHOR_ID'] = $this->userId;

        if (isset($this->temporary['FIRST_MESSAGE'])) {
            $addChat['DESCRIPTION'] = $this->temporary['FIRST_MESSAGE'];
        }

        $chat = new \CIMChat(0);
        $id = $chat->Add($addChat);
        if (!$id) {
            return false;
        }

        $orm = \Bitrix\Im\Model\ChatTable::getById($id);
        $this->chat = $orm->fetch();

        return $this->chat;
    }

    /**
     * @param int $limit
     * @param int $offset
     *
     * @return array
     * @throws \Exception
     */
    public function getList($limit = 10, $offset = 0)
    {
        $arFilter = [
            'select' => ['*', 'AUTHOR_LOGIN' => 'AUTHOR.LOGIN'],
            'filter' => ['CHAT_ID' => $this->chat['ID']],
            'order'  => ['DATE_CREATE' => 'DESC'],
        ];

        if ($limit) {
            $arFilter['limit'] = (int)$limit;
            if ($offset) {
                $arFilter['offset'] = (int)$offset;
            }
        }

        $res = \Bitrix\Im\MessageTable::getList($arFilter);
        $chatItems = [];
        while ($row = $res->fetch()) {
            $chatItems[] = $row;
        }

        return array_reverse($chatItems);
    }

    public function getChat()
    {
        return $this->chat;
    }

    public function addMessage($message)
    {
        global $USER;

        $initParams = [
            'CHAT'           => 'Y',
            'RECIPIENT_ID'   => 'chat' . $this->chat['ID'],
            'OL_SILENT'      => 'Y',
            'MESSAGE'        => $message,
            'TAB'            => 'chat' . $this->chat['ID'],
            'USER_TZ_OFFSET' => 0,
            'IM_AJAX_CALL'   => 'Y',
            'FOCUS'          => 'Y',
        ];

        \CUtil::decodeURIComponent($message);

        $insertID = 0;
        $errorMessage = "";
        if ($initParams['CHAT'] == 'Y'
            && substr($initParams['RECIPIENT_ID'], 0, 4) == 'chat') {
            $userId = $USER->GetId();
            $chatId = (int)substr($initParams['RECIPIENT_ID'], 4);
            if (\CIMChat::GetGeneralChatId() == $chatId && !\CIMChat::CanSendMessageToGeneralChat($userId)) {
                $errorMessage = GetMessage('IM_ERROR_GROUP_CANCELED');
            } else {
                $ar = [
                    "FROM_USER_ID"     => $userId,
                    "TO_CHAT_ID"       => $chatId,
                    "MESSAGE"          => $initParams['MESSAGE'],
                    "SILENT_CONNECTOR" => $initParams['OL_SILENT'] == 'Y' ? 'Y' : 'N',
                ];
                $insertID = \CIMChat::AddMessage($ar);
            }
        } else {
            if (substr($initParams['RECIPIENT_ID'], 0, 4) != 'chat'
                && !\Bitrix\Im\User::getInstance($USER->GetID())->isConnector()) {
                $ar = [
                    "FROM_USER_ID" => intval($USER->GetID()),
                    "TO_USER_ID"   => intval($initParams['RECIPIENT_ID']),
                    "MESSAGE"      => $initParams['MESSAGE'],
                ];
                $insertID = \CIMMessage::Add($ar);
            } else {
                throw new \Exception('Ошибка доступа');
            }
        }

        if (!$insertID && !$errorMessage) {
            if ($e = $GLOBALS["APPLICATION"]->GetException()) {
                throw new \Exception($e->GetString(), 500);
            }
            if (strlen($errorMessage) == 0) {
                throw new \Exception('Неизвестная ошибка', 500);
            }
        }

        $arMsg = \CIMMessenger::GetById($insertID, ['WITH_FILES' => 'Y']);
        $arMessages[$insertID]['params'] = $arMsg['PARAMS'];

        $arMessages = \CIMMessageLink::prepareShow($arMessages, [$insertID => $arMsg['PARAMS']]);

        $ar['MESSAGE'] = trim(str_replace(['[BR]', '[br]'], "\n", $initParams['MESSAGE']));
        $ar['MESSAGE'] = preg_replace("/\[DISK\=([0-9]+)\]/i", "", $ar['MESSAGE']);

        $userTzOffset = isset($initParams['USER_TZ_OFFSET'])
            ? (int)$initParams['USER_TZ_OFFSET']
            : \CTimeZone::GetOffset();
        $arResult = [
            //'TMP_ID' => $tmpID,
            'ID'                 => $insertID,
            'CHAT_ID'            => $arMsg['CHAT_ID'],
            'SEND_DATE'          => time() + $userTzOffset,
            'SEND_MESSAGE'       => \Bitrix\Im\Text::parse($ar['MESSAGE']),
            //'SEND_MESSAGE_PARAMS' => $arMessages[$insertID]['params'],
            'SEND_MESSAGE_FILES' => $arMsg['FILES'],
            'SENDER_ID'          => (int)$USER->GetID(),
            //'RECIPIENT_ID' => $initParams['CHAT'] == 'Y'
            //? htmlspecialcharsbx($initParams['RECIPIENT_ID'])
            //: (int) $initParams['RECIPIENT_ID'],
            'OL_SILENT'          => $initParams['OL_SILENT'],
            'STATUS'             => 'Сообщение сохранено',
            'ERROR'              => $errorMessage,
        ];
        if (isset($initParams['MOBILE'])) {
            $arFormat = [
                "today" => "today, " . GetMessage('IM_MESSAGE_FORMAT_TIME'),
                ""      => GetMessage('IM_MESSAGE_FORMAT_DATE'),
            ];
            $arResult['SEND_DATE_FORMAT'] = FormatDate($arFormat, time() + $userTzOffset);
        }

        \CIMContactList::SetOnline();
        \CIMMessenger::SetCurrentTab($initParams['TAB']);

        return $arResult;
    }

    /**
     * Событие начала печати ответа
     * Обрабатывает События "im", "OnStartWriting"
     */
    public function startWritingEvent()
    {

        $initParams = [
            'DIALOG_ID' => 'chat' . $this->chat['ID'],
            'OL_SILENT' => 'N',
        ];

        return \CIMMessenger::StartWriting(
            $initParams['DIALOG_ID'],
            false,
            "",
            false,
            $initParams['OL_SILENT'] === 'Y'
        );
    }

    public function readMessageEvent($lastMessageId)
    {
        $initParams = [
            'OL_SILENT' => 'N',
            'USER_ID'   => 'chat' . $this->chat['ID'],
            'LAST_ID'   => (int) $lastMessageId ? : null,
        ];

        $CIMChat = new \CIMChat();
        \CIMContactList::SetOnline();

        return $CIMChat->SetReadMessage($this->chat['ID'], $initParams['LAST_ID']);

    }

    public function unReadMessageEvent($lastMessageId)
    {
        $initParams = [
            'OL_SILENT' => 'N',
            'USER_ID'   => 'chat' . $this->chat['ID'],
            'LAST_ID'   => (int) $lastMessageId ? : null,
        ];

        $CIMChat = new \CIMChat();
        return $CIMChat->SetUnReadMessage($initParams['USER_ID'], $initParams['LAST_ID']);
    }

    /**
     * @throws Exception
     */
    public static function checkModules()
    {
        if (!Loader::includeModule('im')) {
            throw new \Exception('Модуль im не установлен', 503);
        }
        if (!Loader::includeModule('imopenlines')) {
            throw new \Exception('Модуль imopenlines не установлен', 503);
        }
    }

}