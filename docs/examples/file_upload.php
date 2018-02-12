<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
CJSCore::Init(['core', 'ajax', 'im', 'jquery']);
?>
<!doctype html>
<html lang="en">
<head>
    <? $APPLICATION->ShowHead(); ?>
</head>

<body>
<form action="/online/file.ajax.php" class="bx-messenger-textarea-file-form">
    <input type="hidden" name="IM_AJAX_CALL" value="Y">
    <input type="file" multiple="multiple" title=" " class="bx-messenger-textarea-file-popup-input" name="bxu_files[]">
</form>

<button onclick="registerFiles();">Загрузка</button>

<script>
    function getId() {
        return (new Date().valueOf() + Math.round(Math.random() * 1000000));
    }

    function registerFiles() {
        //Хэш результат работы метода open
        const chatHash = '964c2fb51b56d02d84192b713a52f016';

        var file_data = $('.bx-messenger-textarea-file-popup-input').prop('files');

        var filesLine = {};
        var files_count = 0;
        for (var fileIndex in file_data) {
            if (!file_data.hasOwnProperty(fileIndex)) continue;
            var newFile = file_data[fileIndex];
            var fileId = 'file' + getId();
            filesLine[fileId] = {
                "id": fileId,
                "type": "file",
                "mimeType": newFile.type,
                "name": newFile.name,
                "size": newFile.size
            };
            files_count++;
        }

        if (files_count) {
            var filesLineStr = JSON.stringify(filesLine);
            console.log(filesLineStr);
        }

        //var filesLine = '{"' + fileId1 + '":{"id":"' + fileId1 + '","type":"file","mimeType":"application/json","name":"composer.json","size":628},"' + fileId2 + '":{"id":"' + fileId2 + '","type":"file","mimeType":"text/markdown","name":"README.md","size":1299}}';

        BX.ajax({
            url: '/pub/api/v1/chat/ks/' + chatHash + '/files_register',
            method: 'POST',
            dataType: 'json',
            skipAuthCheck: true,
            timeout: 30,
            data: {FILES: filesLineStr},
            onsuccess: function (data) {
                console.log(data);
                var file_data = $('.bx-messenger-textarea-file-popup-input').prop('files');
                var form_data = new FormData();

                var messagefileId = [];
                var filesProgress = {};
                var files_counter = 0;
                var reg_params = {};
                for (var tmpId in data.FILE_ID) {
                    var newFile = data.FILE_ID[tmpId];
                    reg_params[newFile.TMP_ID] = newFile.FILE_ID;

                    form_data.append( 'bxu_files[' + newFile.TMP_ID + '][name]', file_data[files_counter].name);
                    form_data.append( 'bxu_files[' + newFile.TMP_ID + '][type]', file_data[files_counter].type);
                    form_data.append( 'bxu_files[' + newFile.TMP_ID + '][size]', file_data[files_counter].size);
                    form_data.append( 'bxu_files[' + newFile.TMP_ID + '][default]', file_data[files_counter]);
                    files_counter++;
                }
                //form_data.append('bxu_info[controlId]', "bitrixUploader");
                //form_data.append('bxu_info[CID]', "CID" + getId());
                //form_data.append('bxu_info[inputName]', "bxu_files");
                //form_data.append('bxu_info[version]', "1");
                //form_data.append('bxu_info[packageIndex]', "pIndex" + getId());
                //form_data.append('bxu_info[mode]', "upload");
                //form_data.append('CHAT_ID', data.CHAT_ID);
                form_data.append('bxu_info[filesCount]', files_counter);
                form_data.append('REG_PARAMS', JSON.stringify(reg_params));
                form_data.append('REG_CHAT_ID', data.CHAT_ID);
                form_data.append('REG_MESSAGE_ID', data.MESSAGE_ID);

                BX.ajax({
                    url: '/pub/api/v1/chat/ks/' + chatHash + '/files_upload',
                    dataType: 'json',
                    method: 'POST',
                    skipAuthCheck: true,
                    preparePost: false,
                    cache: false,
                    timeout: 30,
                    data: form_data,
                    onsuccess: function (php_script_response) {
                        console.log(php_script_response);
                    }
                });
            }

        });

    }


</script>
</body>
</html>
