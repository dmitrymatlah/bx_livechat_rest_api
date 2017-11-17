<?php
/**
 * Created by PhpStorm.
 * User: mclaod
 * Date: 16.11.17
 * Time: 13:54
 */

namespace BxLivechatRestApi\Utils;


use Bitrix\Main\Server;
use Bitrix\Main\UI\FileInputUtility;
use Bitrix\Main\UI\Uploader\File;
use Bitrix\Main\UI\Uploader\Package;

class FileUploader extends \Bitrix\Main\UI\Uploader\Uploader {

    public function __construct($params, $doCheckPost = true)
    {
        parent::__construct($params);

        $this->request = new \Bitrix\Main\HttpRequest(new Server($_SERVER), $_GET, $_POST, $_FILES, $_COOKIE);

        if ($doCheckPost !== false)
        {
            $this->checkPost(($doCheckPost === true || $doCheckPost === "post"));
        }


    }

    /**
     * @param bool $checkPost
     *
     * @return bool
     * @throws \Bitrix\Main\NotImplementedException
     * @throws \Exception
     * @throws \Bitrix\Main\ArgumentNullException
     */

    public function checkPost($checkPost = true)
    {
        if (($checkPost === false && !is_array($this->request->getQuery(self::INFO_NAME)))
            || ($checkPost !== false && !is_array($this->request->getPost(self::INFO_NAME)))
        ) {
            return false;
        }

        if ($checkPost === false) {
            $this->setRequestMethodToCheck(["get"]);
        }

        $this->fillRequireData();
        $cid = FileInputUtility::instance()->registerControl($this->getRequest("CID"), $this->controlId);

        if ($this->mode === "upload") {
            $package = new Package(
                $this->path,
                $cid,
                $this->getRequest("packageIndex")
            );
            $package
                ->setStorage($this->params["storage"])
                ->setCopies($this->params["copies"]);

            $response = $package->checkPost($this->params);


                $response2 = [];
                foreach ($response as $k => $r) {
                    $response2[$k] = [
                        "hash"   => $r["hash"],
                        "status" => $r["status"],
                        "file"   => $r,
                    ];
                    if (isset($r["error"]))
                        $response2[$k]["error"] = $r["error"];
                }
                $result = [
                    "status"  => $package->getLog("uploadStatus") === "uploaded" ? "done" : "inprogress",
                    "package" => [
                        $package->getIndex() => array_merge(
                            $package->getLog(),
                            [
                                "executed"   => $package->getLog("executeStatus") === "executed",
                                "filesCount" => $package->getLog("filesCount"),
                            ]
                        ),
                    ],
                    "report"  => [
                        "uploading" => [
                            $package->getCid() => $package->getCidLog(),
                        ],
                    ],
                    "files"   => self::prepareData($response2),
                ];
                return $result;

        } else if($this->mode === "delete") {
            $cid = FileInputUtility::instance()->registerControl($this->getRequest("CID"), $this->controlId);
            File::deleteFile($cid, $this->getRequest("hash"), $this->path);
        } else {
            File::viewFile($cid, $this->getRequest("hash"), $this->path);
        }

        return true;
    }
}