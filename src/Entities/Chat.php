<?php

namespace BxLivechatRestApi\Entities;

use \Bitrix\Main\Localization\Loc;
use \Bitrix\Main\Loader;
use \Bitrix\ImOpenLines\LiveChat as BitrixLiveChat;
use BxLivechatRestApi\Utils\FileUploader;
use BxLivechatRestApi\Utils\ImagePreviewSizeFilter;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;

class Chat extends BitrixLiveChat
{
    private $aliasData;

    private $configId;

    private $config = null;

    private $sessionId = null;

    private $temporary = [];

    private $userId = null;

    private $chat = null;

    const SYSTEM_AVATAR = '/upload/system_avatar.png';
    const SYSTEM_NAME = 'Petstory';
    const LOCATION_LEAD_USER_FIELD_NAME = 'Город пользователя';

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
                    '=XML_ID' => 'livechat|' . $liveChatHash,
                ],
                'limit' => 1,
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

    protected function fillTemporaryParams()
    {
        if (isset($_REQUEST['userName'])) {
            $this->temporary['USER_NAME'] = $_REQUEST['userName'];
        }
        if (isset($_REQUEST['userLastName'])) {
            $this->temporary['USER_LAST_NAME'] = $_REQUEST['userLastName'];
        }
        if (isset($_REQUEST['userAvatar'])) {
            $this->temporary['USER_AVATAR'] = $_REQUEST['userAvatar'];
        }
        if (isset($_REQUEST['userEmail'])) {
            $this->temporary['USER_EMAIL'] = $_REQUEST['userEmail'];
        }
        if (isset($_REQUEST['userLocation'])) {
            $this->temporary['USER_LOCATION'] = htmlspecialcharsEx($_REQUEST['userLocation']);
        }
        if (isset($_REQUEST['currentUrl']) && !empty($_REQUEST['currentUrl'])) {
            $currentUrl = parse_url($_REQUEST['currentUrl']);
            if ($currentUrl) {
                $this->temporary['USER_PERSONAL_WWW'] = $_REQUEST['currentUrl'];
            }
        }

        $userLocationFirstMessageString = '[b]' . self::LOCATION_LEAD_USER_FIELD_NAME . '[/b]:' .$this->temporary['USER_LOCATION'];

        if (isset($_REQUEST['firstMessage'])) {
            $this->temporary['FIRST_MESSAGE'] = $_REQUEST['firstMessage'] . $userLocationFirstMessageString;
        } else if (isset($_REQUEST['currentUrl']) && !empty($_REQUEST['currentUrl'])) {
            $currentUrl = parse_url($_REQUEST['currentUrl']);
            if ($currentUrl) {
                $this->temporary['FIRST_MESSAGE'] = '[b]' . Loc::getMessage('IMOL_LC_GUEST_URL') . '[/b]: [url=' . $_REQUEST['currentUrl'] . ']' . $currentUrl['scheme'] . '://' . $currentUrl['host'] . '/' . $currentUrl['path'] . '[/url]'.$userLocationFirstMessageString;
            }
        }
    }
    
    /**
     * Открываем сессию открытой линии
     *
     * @param string $liveChatHash
     *
     * @return null|string
     * @throws \Exception
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

        $this->fillTemporaryParams();

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

    /**
     * @param null $userId
     *
     * @return int
     */
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
        $userLocationCity = isset($this->temporary['USER_LOCATION']) ? $this->temporary['USER_LOCATION'] : '';
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

        $orm = \Bitrix\Main\UserTable::getList(
            [
                'filter' => [
                    '=EXTERNAL_AUTH_ID' => self::EXTERNAL_AUTH_ID,
                    '=XML_ID' => 'livechat|' . $xmlId,
                ],
                'limit' => 1,
            ]
        );

        if ($userFields = $orm->fetch()) {
            $userId = $userFields['ID'];

            $updateFields = [];
            if ($userWebsite && $userWebsite != $userFields['PERSONAL_WWW']) {
                $updateFields['PERSONAL_WWW'] = $userWebsite;
            }
            if ($userLocationCity && $userLocationCity != $userFields['PERSONAL_CITY']) {
                $updateFields['PERSONAL_CITY'] = $userLocationCity;
            }

            if (!empty($updateFields)) {
                $cUser = new \CUser;
                $cUser->Update($userId, $updateFields);
            }
        } else {
            $cUser = new \CUser;
            $fields['LOGIN'] = self::MODULE_ID . '_' . mt_rand(1000, 9999) . randString(5);
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
            $fields['PERSONAL_CITY'] = $userLocationCity;
            $fields['PASSWORD'] = md5($fields['LOGIN'] . '|' . mt_rand(1000, 9999) . '|' . time());
            $fields['CONFIRM_PASSWORD'] = $fields['PASSWORD'];
            $fields['EXTERNAL_AUTH_ID'] = self::EXTERNAL_AUTH_ID;
            $fields['XML_ID'] = 'livechat|' . $xmlId;
            $fields['ACTIVE'] = 'Y';

            $userId = $cUser->Add($fields);
        }

        return $userId;
    }

    /**
     * @return array|bool|false|null
     */
    private function getChatForUser()
    {
        $orm = \Bitrix\Im\Model\ChatTable::getList(
            [
                'filter' => [
                    '=ENTITY_TYPE' => 'LIVECHAT',
                    '=ENTITY_ID' => $this->config['ID'] . '|' . $this->userId,
                ],
                'limit' => 1,
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
     * Установка строки местоположения
     * в профиль пользователя Битрикс
     *
     * @param Request $request
     * @throws \Exception
     */
    public function setChatUserLocation(Request $request)
    {
        $location = $request->get('LOCATION');
        if(empty($location)){
            throw new \Exception('Empty Location string');
        }

        $updateFields = [];
        $updateFields['PERSONAL_CITY'] = $location;

        $cUser = new \CUser;
        $cUser->Update($this->userId, $updateFields);

        /*Обновим поле лида*/
        $arEntityData = explode('|', $this->chat['ENTITY_DATA_1']);

        $linkedLeadId = (int) $arEntityData[2];

        if(!empty($linkedLeadId)){
            $locationUserFieldId = $this->getLeadUserFieldIdByName(self::LOCATION_LEAD_USER_FIELD_NAME);
            if(!empty($locationUserFieldId)){
                $oLead = new \CCrmLead;
                $arFields[$locationUserFieldId] = trim($location);
                $oLead->Update($linkedLeadId, $arFields);
            }
        }

        return ['STATUS' => 'OK'];
    }

    /**
     * @param $name
     */
    protected function getLeadUserFieldIdByName($name)
    {
        global $USER_FIELD_MANAGER;

        $CCrmFields = new \CCrmFields($USER_FIELD_MANAGER, 'CRM_LEAD');

        $arFields = $CCrmFields->GetFields();
        foreach($arFields as $id => $arField){
            if($arField['EDIT_FORM_LABEL'] === self::LOCATION_LEAD_USER_FIELD_NAME){
                return $id;
            }
        }
        return false;

    }


    /**
     * @param int $limit
     * @param int $offset
     *
     * @return array
     * @throws \Exception
     */
    public function getList($app, $limit = 10, $offset = 0)
    {
        $arFilter = [
            'select' => ['*', 'AUTHOR_LOGIN' => 'AUTHOR.LOGIN'],
            'filter' => ['CHAT_ID' => $this->chat['ID']],
            'order' => ['DATE_CREATE' => 'DESC'],
        ];

        if ($limit) {
            $arFilter['limit'] = (int)$limit;
            if ($offset) {
                $arFilter['offset'] = (int)$offset;
            }
        }

        $res = \Bitrix\Im\MessageTable::getList($arFilter);
        $chatItems = [];
        $result = [];
        $arUsers = [];
        while ($row = $res->fetch()) {
            $chatItems[$row['ID']] = $row;
            if (!empty($row['AUTHOR_ID'])) {
                $arUsers[] = (int)$row['AUTHOR_ID'];
            }
        }

        $result['LINE_NAME'] = $this->config['LINE_NAME'];
        $result['SYSTEM_NAME'] = self::SYSTEM_NAME;
        $result['SYSTEM_AVATAR'] = self::SYSTEM_AVATAR;
        $result['MESSAGE'] = $chatItems;

        if (!empty($chatItems)) {
            //Получаем параметры сообщений
            //флаги удалено и редактировалось
            $messageParams = $this->getMessagesParams(array_keys($chatItems));

            $arFiles = [];
            foreach ($chatItems as $id => $data) {
                $result['MESSAGE'][$id]['IS_DELETED'] = $messageParams[$id]['IS_DELETED'][0] === 'Y';
                $result['MESSAGE'][$id]['IS_EDITED'] = $messageParams[$id]['IS_EDITED'][0] === 'Y';

                if (isset($messageParams[$id]['FILE_ID'])) {
                    foreach ($messageParams[$id]['FILE_ID'] as $fileId) {
                        $arFiles[$fileId] = $fileId;
                        $result['MESSAGE'][$id]['FILES'][] = $fileId;
                    }
                }
            }
            $result['FILES'] = \CIMDisk::GetFiles($this->chat['ID'], $arFiles);

            foreach($result['FILES'] as &$fileData){
                $fileData['urlShow'] = self::getFileUrl($app, 'show', $fileData['id']);
                $fileData['urlDownload'] = self::getFileUrl($app,'download', $fileData['id']);
                if($fileData['type'] === 'image'){
                    $fileData['urlPreview'] = self::getFileUrl($app,'preview', $fileData['id']);;
                }
            }

            $result['USERS'] = [];
            if (!empty($arUsers)) {
                $ar = \CIMContactList::GetUserData([
                    'ID' => array_unique($arUsers),
                    'DEPARTMENT' => 'Y',
                    'USE_CACHE' => 'N',
                    'SHOW_ONLINE' => 'Y',
                    'PHONES' => IsModuleInstalled('voximplant') ? 'Y' : 'N'
                ]);
            }
            $result['USERS'] = $ar['users'];
        }

        return $result;
    }

    public static function getFileUrl(Application $app, $action, $fileId){
        $fileIdGetParamName = 'fileId';
        return $app['config.basePath'].$app['chat.alias'].'/'.$app['chat.hash'].'/file/'.$action.'?'.$fileIdGetParamName.'='.$fileId;
    }

    protected function getMessagesParams(array $messagesList)
    {
        $arResult = [];
        $filter = [
            '=MESSAGE_ID' => $messagesList,
        ];
        $messageParameters = \Bitrix\IM\Model\MessageParamTable::getList(
            [
                'select' => ['ID', 'MESSAGE_ID', 'PARAM_NAME', 'PARAM_VALUE', 'PARAM_JSON'],
                'filter' => $filter,
            ]
        );
        while ($ar = $messageParameters->fetch()) {
            if (strlen($ar["PARAM_JSON"])) {
                $value = \Bitrix\Main\Web\Json::decode($ar["PARAM_JSON"]);
            } else {
                $value = $ar["PARAM_VALUE"];
            }
            if ($ar["PARAM_NAME"] == 'KEYBOARD') {
                $arResult[$ar["MESSAGE_ID"]][$ar["PARAM_NAME"]] = $value;
            } else {
                $arResult[$ar["MESSAGE_ID"]][$ar["PARAM_NAME"]][] = $value;
            }
        }

        return $arResult;
    }

    /**
     * @return array
     */
    public function getChat()
    {
        return $this->chat;
    }

    /**
     * @param $message
     *
     * @return array
     * @throws \Exception
     */
    public function addMessage($message)
    {
        global $USER;

        $initParams = [
            'CHAT' => 'Y',
            'RECIPIENT_ID' => 'chat' . $this->chat['ID'],
            'OL_SILENT' => 'Y',
            'MESSAGE' => $message,
            'TAB' => 'chat' . $this->chat['ID'],
            'USER_TZ_OFFSET' => 0,
            'IM_AJAX_CALL' => 'Y',
            'FOCUS' => 'Y',
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
                    "FROM_USER_ID" => $userId,
                    "TO_CHAT_ID" => $chatId,
                    "MESSAGE" => $initParams['MESSAGE'],
                    "SILENT_CONNECTOR" => $initParams['OL_SILENT'] == 'Y' ? 'Y' : 'N',
                ];
                $insertID = \CIMChat::AddMessage($ar);
            }
        } else {
            if (substr($initParams['RECIPIENT_ID'], 0, 4) != 'chat'
                && !\Bitrix\Im\User::getInstance($USER->GetID())->isConnector()) {
                $ar = [
                    "FROM_USER_ID" => (int)$USER->GetID(),
                    "TO_USER_ID" => (int)$initParams['RECIPIENT_ID'],
                    "MESSAGE" => $initParams['MESSAGE'],
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
            'ID' => $insertID,
            'CHAT_ID' => $arMsg['CHAT_ID'],
            'SEND_DATE' => time() + $userTzOffset,
            'SEND_MESSAGE' => \Bitrix\Im\Text::parse($ar['MESSAGE']),
            //'SEND_MESSAGE_PARAMS' => $arMessages[$insertID]['params'],
            'SEND_MESSAGE_FILES' => $arMsg['FILES'],
            'SENDER_ID' => (int)$USER->GetID(),
            //'RECIPIENT_ID' => $initParams['CHAT'] == 'Y'
            //? htmlspecialcharsbx($initParams['RECIPIENT_ID'])
            //: (int) $initParams['RECIPIENT_ID'],
            'OL_SILENT' => $initParams['OL_SILENT'],
            'STATUS' => 'Сообщение сохранено',
            'ERROR' => $errorMessage,
        ];
        if (isset($initParams['MOBILE'])) {
            $arFormat = [
                "today" => "today, " . GetMessage('IM_MESSAGE_FORMAT_TIME'),
                "" => GetMessage('IM_MESSAGE_FORMAT_DATE'),
            ];
            $arResult['SEND_DATE_FORMAT'] = FormatDate($arFormat, time() + $userTzOffset);
        }

        \CIMContactList::SetOnline();
        \CIMMessenger::SetCurrentTab($initParams['TAB']);

        return $arResult;
    }

    /**
     * @param Request $request
     *
     * @return array
     * @throws \Exception
     */
    public function editMessage(Request $request, $messageId)
    {
        $initParams = [
            'MESSAGE_ID' => (int)$messageId,
            'MESSAGE' => $request->get('MESSAGE'),
            'USER_TZ_OFFSET' => null !== $request->get('USER_TZ_OFFSET')
                ? (int)$request->get('USER_TZ_OFFSET') : \CTimeZone::GetOffset(),
        ];

        \CUtil::decodeURIComponent($initParams);

        if (\CIMMessenger::Update($initParams['MESSAGE_ID'], $initParams['MESSAGE'])) {
            $arResult = [
                'ID' => $initParams['MESSAGE_ID'],
                'MESSAGE' => \Bitrix\Im\Text::parse($initParams['MESSAGE']),
                'DATE' => time() + $initParams['USER_TZ_OFFSET'],
            ];

            return $arResult;
        }

        throw new \Exception('CANT_EDIT_MESSAGE', 403);
    }

    /**
     * @param Request $request
     *
     * @return bool
     * @throws \Exception
     */
    public function deleteMessage($messageId)
    {
        $initParams = [
            'MESSAGE_ID' => (int)$messageId,
        ];

        if (\CIMMessenger::Delete($initParams['MESSAGE_ID'])) {
            return true;
        }

        throw new \Exception('CANT_DELETE_MESSAGE', 403);
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
            'USER_ID' => 'chat' . $this->chat['ID'],
            'LAST_ID' => (int)$lastMessageId ?: null,
        ];

        $CIMChat = new \CIMChat();
        \CIMContactList::SetOnline();

        return $CIMChat->SetReadMessage($this->chat['ID'], $initParams['LAST_ID']);

    }

    public function unReadMessageEvent($lastMessageId)
    {
        $initParams = [
            'OL_SILENT' => 'N',
            'USER_ID' => 'chat' . $this->chat['ID'],
            'LAST_ID' => (int)$lastMessageId ?: null,
        ];

        $CIMChat = new \CIMChat();

        return $CIMChat->SetUnReadMessage($initParams['USER_ID'], $initParams['LAST_ID']);
    }

    public function filesRegister(Request $request)
    {
        if (!Loader::includeModule('disk')) {
            throw new \Exception('Модуль disk не установлен', 503);
        }

        $initParams = [
            'OL_SILENT' => 'N',
            'CHAT_ID' => $this->chat['ID'],
            'FILES' => \CUtil::JsObjectToPhp($request->get('FILES')),
            'MESSAGE_TMP_ID' => $request->get('MESSAGE_TMP_ID') ?: 'tempFile' + rand(0, 1000),
            'TEXT' => $request->get('TEXT'),

        ];
        \CUtil::decodeURIComponent($initParams['TEXT']);

        /*
         * $initParams['FILES'] должен быть в формате {"file1510576968354":{"id":"file1510576968354","type":"file","mimeType":"application/json","name":"composer.json","size":628},...}
        */
        $result = \CIMDisk::UploadFileRegister(
            $initParams['CHAT_ID'],
            $initParams['FILES'],
            $initParams['TEXT'],
            $initParams['OL_SILENT'] === 'Y'
        );
        if (!$result) {
            throw new \Exception('Файлы не зарегистрированы', 403);
        }

        if ($initParams['TEXT']) {
            $ar['MESSAGE'] = trim(str_replace(['[BR]', '[br]'], "\n", $initParams['TEXT']));
            $ar['MESSAGE'] = preg_replace("/\[DISK\=([0-9]+)\]/i", "", $ar['MESSAGE']);
            $ar['MESSAGE'] = \Bitrix\Im\Text::parse($ar['MESSAGE']);
        } else {
            $ar['MESSAGE'] = '';
        }

        return [
            'FILE_ID' => $result['FILE_ID'],
            'CHAT_ID' => $initParams['CHAT_ID'],
            'MESSAGE_TEXT' => $ar['MESSAGE'],
            'MESSAGE_ID' => $result['MESSAGE_ID'],
            'MESSAGE_TMP_ID' => $initParams['MESSAGE_TMP_ID'],
        ];
    }

    public function filesUnregister(Request $request)
    {

        /*
         * $initParams['FILES'] должен быть в формате {"file1510576968354":{"id":"file1510576968354","type":"file","mimeType":"application/json","name":"composer.json","size":628},...}
        */
        $initParams = [
            'FILES' => \CUtil::JsObjectToPhp($request->get('FILES')),
            'MESSAGES' => \CUtil::JsObjectToPhp($request->get('MESSAGES')),
            'CHAT_ID' => $this->chat['ID'],
        ];

        $result = \CIMDisk::UploadFileUnRegister($initParams['CHAT_ID'], $initParams['FILES'], $initParams['MESSAGES']);

        if (!$result) {
            throw new Exception('Ошибка сброса регистрации', 403);
        }

        return [
            'STATUS' => 'OK',
        ];
    }

    public function getFile(Request $request, $action){
        //Необходимо передавать fileId в query string, т.к.  \Bitrix\Disk\DownloadController()
        //будет при проверке искать его там.
        $_GET['fileId'] = (int) $_GET['fileId'];
        switch ($action){
            case 'show':
                $_GET['action'] = 'showFile';
                break;
            case 'preview':
                $_GET['action'] = 'showFile';
                $_GET['preview'] = 'Y';
                //класс фильтра для ресайза изображения перед отдачей
                \Bitrix\Main\Application::getInstance()->getContext()->getRequest()->addFilter(new ImagePreviewSizeFilter);
                break;
            case 'download':
            default:
                $_GET['action'] = 'downloadFile';
        }

        $controller = new \Bitrix\Disk\DownloadController();
        $controller->setActionName($_GET['action'])->exec();

    }

    public static function getPseudoUniqueId()
    {
        return time() + ceil(mt_rand() / mt_getrandmax() * 1000000);
    }

    public function filesUpload()
    {

        if (!Loader::includeModule('disk')) {
            throw new \Exception('Модуль disk не установлен', 503);
        }
        $initParams = [];

        $initParams['sessid'] = bitrix_sessid();

        $initParams['AJAX_POST'] = 'Y';
        $initParams['USER_ID'] = $this->userId;
        $initParams['size'] = '10';
        $initParams['IM_FILE_UPLOAD'] = 'Y';
        $initParams[FileUploader::INFO_NAME] = [
            'controlId' => 'bitrixUploader',
            'CID' => 'CID' . self::getPseudoUniqueId(),
            'inputName' => FileUploader::FILE_NAME,
            'version' => '1',
            'packageIndex' => 'pIndex' . self::getPseudoUniqueId(),
            'mode' => 'upload',
        ];
        $initParams[FileUploader::INFO_NAME]['filesCount'] = is_array($_REQUEST[FileUploader::FILE_NAME])
            ? count($_REQUEST[FileUploader::FILE_NAME])
            : 0;
        $initParams['CHAT_ID'] = $this->chat['ID'];
        $initParams['REG_CHAT_ID'] = $this->chat['ID'];

        $_REQUEST = array_merge($_REQUEST, $initParams);
        $_POST = array_merge($_POST, $initParams);

        $CFileUploader = new FileUploader(
            [
                "allowUpload" => "A",
                "events" => [
                    "onFileIsUploaded" => ["CIMDisk", "UploadFile"],
                ],
            ]
        );
        if (!$result = $CFileUploader->checkPost()) {
            throw new \Exception('UPLOAD_ERROR', 403);
        }

        return $result;
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
