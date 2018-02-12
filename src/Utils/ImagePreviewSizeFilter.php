<?php
/**
 * Created by PhpStorm.
 * User: Trend
 * Date: 11.02.2018
 * Time: 20:59
 */

namespace BxLivechatRestApi\Utils;

class ImagePreviewSizeFilter implements \Bitrix\Main\Type\IRequestFilter
{
    /**
     * @param array $values
     * @return array
     */
    public function filter(array $values)
    {
        if($_GET['action'] == 'showFile')
        {
            $values['get']['action'] = 'showFile';
            if($_GET['preview'] == 'Y')
            {
                $values['get']['width'] = 500;
                $values['get']['height'] = 500;
                $values['get']['signature'] = \Bitrix\Disk\Security\ParameterSigner::getImageSignature(
                    $values['get']['fileId'], $values['get']['width'], $values['get']['height']
                );
            }
            else
            {
                unset($values['get']['width'], $values['get']['height']);
            }
            unset($values['get']['exact']);
        }
        else
        {
            $values['get']['action'] = 'downloadFile';
        }

        return array(
            'get' => $values['get'],
        );
    }
}