<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Http\Controllers;


use Illuminate\Http\Request;
use Intervention\Image\Facades\Image;

class FileController extends Controller
{
    /**
     * 主要是给处理一下不同的上传组件所需要的数据格式
     * @var string
     */
    private $uploadFrom = '';
    /**
     * 上传图片文件
     *
     * @param Request $request
     *
     * @return array
     */
    public function imageUpload(Request $request)
    {
        $this->uploadFrom = $request->input('from');
        $file = $request->file('editormd-image-file');
        if (!$file->isValid()) {
            return $this->response(false,
                __('common.upload.failed', ['reason' => $file->getErrorMessage()]));
        }

        if (!in_array(strtolower($file->extension()), ["jpg", "jpeg", "gif", "png", "bmp", "svg"])) {
            return $this->response(false, __('common.upload.invalid_type') . '.' . strtolower($file->extension()));
        }

        $path = $file->storePublicly(sprintf('public/%s', date('Y/m-d')));
        $file1 = public_path('storage/' . substr($path, 7));
        watermark($file1);
        return $this->response(true, __('common.upload.success'), \Storage::url($path));
    }

    private function response(bool $isSuccess, string $message, $url = null)
    {
        $result = [
            'success' => $isSuccess ? 1 : 0,
            'message' => $message,
            'url'     => $url,
        ];
        if($this->uploadFrom == 'wangEditor') {
            $result = [
                'errno' => $isSuccess ? 0 : 1,
                'message' => $message,
                'data' => [
                    [
                        'url' => $url,
                        'alt' => '',
                        'href' => ''
                    ]
                ]
            ];
        }
        return $result;
    }

}