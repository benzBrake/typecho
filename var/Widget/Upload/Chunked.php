<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 分片上传动作
 *
 * @category typecho
 * @package Widget
 * @copyright Copyright (c) 2008 Typecho team (http://www.typecho.org)
 * @license GNU General Public License 2.0
 * @version $Id$
 */

/**
 * 分片上传组件
 *
 * @author qining
 * @category typecho
 * @package Widget
 */
class Widget_Upload_Chunked extends Widget_Abstract_Contents implements Widget_Interface_Do
{
    //上传文件目录
    const UPLOAD_DIR = '/usr/uploads';
    //分片临时目录
    const CHUNK_DIR = '/usr/uploads/chunks';

    /**
     * 创建上传路径
     *
     * @access private
     * @param string $path 路径
     * @return boolean
     */
    private static function makeUploadDir($path)
    {
        $path = preg_replace("/\\\+/", '/', $path);
        $current = rtrim($path, '/');
        $last = $current;

        while (!is_dir($current) && false !== strpos($path, '/')) {
            $last = $current;
            $current = dirname($current);
        }

        if ($last == $current) {
            return true;
        }

        if (!@mkdir($last)) {
            return false;
        }

        $stat = @stat($last);
        $perms = $stat['mode'] & 0007777;
        @chmod($last, $perms);

        return self::makeUploadDir($path);
    }

    /**
     * 获取安全的文件名
     *
     * @param string $name
     * @static
     * @access private
     * @return string
     */
    private static function getSafeName(&$name)
    {
        $name = str_replace(array('"', '<', '>'), '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);

        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    /**
     * 检查文件名
     *
     * @access private
     * @param string $ext 扩展名
     * @return boolean
     */
    public static function checkFileType($ext)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        return in_array($ext, $options->allowedAttachmentTypes);
    }

    /**
     * 清理过期的分片文件
     *
     * @access private
     * @return void
     */
    private function cleanExpiredChunks()
    {
        $chunkDir = Typecho_Common::url(
            defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : self::CHUNK_DIR,
            defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__
        );

        if (!is_dir($chunkDir)) {
            return;
        }

        $files = scandir($chunkDir);
        $expireTime = time() - 86400; // 24小时过期

        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }

            $filePath = $chunkDir . '/' . $file;

            if (is_file($filePath) && filemtime($filePath) < $expireTime) {
                @unlink($filePath);
            }
        }
    }

    /**
     * 处理分片上传
     *
     * @access public
     * @return void
     */
    public function upload()
    {
        if (empty($_FILES)) {
            $this->response->throwJson(false);
        }

        $file = array_pop($_FILES);

        if ($file['error'] != 0) {
            $this->response->throwJson(false);
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $this->response->throwJson(false);
        }

        // 获取真实文件名（plupload分片上传时，文件名在POST参数中）
        $realFileName = $file['name'];
        if (isset($_POST['name'])) {
            $realFileName = $_POST['name'];
            // xhr的send无法支持utf8
            $realFileName = urldecode($realFileName);
        }

        // 获取分片参数（plupload通过POST传递分片参数）
        $chunkId = $this->request->get('chunk_id');
        $chunkIndex = $this->request->get('chunk', 0); // plupload使用 'chunks' 和 'chunk'
        $totalChunks = $this->request->get('chunks', 1);
        $totalSize = $this->request->get('size', 0);

        // 尝试从POST获取分片参数（plupload兼容）
        if (empty($chunkId) && isset($_POST['chunk_id'])) {
            $chunkId = $_POST['chunk_id'];
        }
        if (isset($_POST['chunk'])) {
            $chunkIndex = intval($_POST['chunk']);
        }
        if (isset($_POST['chunks'])) {
            $totalChunks = intval($_POST['chunks']);
        }
        if (isset($_POST['size'])) {
            $totalSize = intval($_POST['size']);
        }

        if (empty($chunkId)) {
            // 如果没有chunk_id，根据真实文件名和大小生成一个
            $chunkId = md5($realFileName . $totalSize);
        }

        // 验证文件类型（使用真实文件名）
        $ext = self::getSafeName($realFileName);

        if (!self::checkFileType($ext)) {
            $this->response->throwJson(false);
        }

        if (Typecho_Common::isAppEngine()) {
            $this->response->throwJson(false);
        }

        // 创建分片临时目录
        $chunkDir = Typecho_Common::url(
            defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : self::CHUNK_DIR,
            defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__
        );

        if (!is_dir($chunkDir)) {
            if (!self::makeUploadDir($chunkDir)) {
                $this->response->throwJson(false);
            }
        }

        // 检查目录是否可写
        if (!is_writable($chunkDir)) {
            $this->response->throwJson(false);
        }

        // 保存分片
        $chunkFile = $chunkDir . '/' . $chunkId . '.part' . $chunkIndex;

        if (!move_uploaded_file($file['tmp_name'], $chunkFile)) {
            $this->response->throwJson(false);
        }

        // 检查是否所有分片都已上传
        $allChunksUploaded = true;
        for ($i = 0; $i < $totalChunks; $i++) {
            if (!file_exists($chunkDir . '/' . $chunkId . '.part' . $i)) {
                $allChunksUploaded = false;
                break;
            }
        }

        // 如果所有分片都上传完成，进行合并
        if ($allChunksUploaded) {
            $date = new Typecho_Date();
            $path = Typecho_Common::url(
                (defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : self::UPLOAD_DIR)
                    . '/' . $date->year . '/' . $date->month,
                defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__
            );

            //创建上传目录
            if (!is_dir($path)) {
                if (!self::makeUploadDir($path)) {
                    $this->response->throwJson(false);
                }
            }

            //获取文件名
            $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
            $finalPath = $path . '/' . $fileName;

            // 合并分片
            $finalFile = fopen($finalPath, 'wb');

            if (!$finalFile) {
                $this->response->throwJson(false);
            }

            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkFilePath = $chunkDir . '/' . $chunkId . '.part' . $i;
                $chunkData = file_get_contents($chunkFilePath);

                if ($chunkData === false) {
                    fclose($finalFile);
                    @unlink($finalPath);
                    $this->response->throwJson(false);
                }

                fwrite($finalFile, $chunkData);
                @unlink($chunkFilePath); // 删除已合并的分片
            }

            fclose($finalFile);

            // 清理可能残留的分片文件
            for ($i = 0; $i < $totalChunks; $i++) {
                @unlink($chunkDir . '/' . $chunkId . '.part' . $i);
            }

            $fileSize = filesize($finalPath);

            // 触发插件接口
            $result = array(
                'name' => $realFileName,
                'path' => (defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : self::UPLOAD_DIR)
                    . '/' . $date->year . '/' . $date->month . '/' . $fileName,
                'size' => $fileSize,
                'type' => $ext,
                'mime' => Typecho_Common::mimeContentType($finalPath)
            );

            $result = Typecho_Plugin::factory('Widget_Upload')->trigger($hasUploaded)->uploadHandle($result);

            if ($hasUploaded && false === $result) {
                @unlink($finalPath);
                $this->response->throwJson(false);
            }

            $this->pluginHandle()->beforeUpload($result);

            $struct = array(
                'title'     =>  $result['name'],
                'slug'      =>  $result['name'],
                'type'      =>  'attachment',
                'status'    =>  'publish',
                'text'      =>  serialize($result),
                'allowComment'      =>  1,
                'allowPing'         =>  0,
                'allowFeed'         =>  1
            );

            if (isset($this->request->cid)) {
                $cid = $this->request->filter('int')->cid;

                if ($this->isWriteable($this->db->sql()->where('cid = ?', $cid))) {
                    $struct['parent'] = $cid;
                }
            }

            $insertId = $this->insert($struct);

            $this->db->fetchRow($this->select()->where('table.contents.cid = ?', $insertId)
            ->where('table.contents.type = ?', 'attachment'), array($this, 'push'));

            /** 增加插件接口 */
            $this->pluginHandle()->upload($this);

            // 清理过期分片
            $this->cleanExpiredChunks();

            $this->response->throwJson(array($this->attachment->url, array(
                'cid'       =>  $insertId,
                'title'     =>  $this->attachment->name,
                'type'      =>  $this->attachment->type,
                'size'      =>  $this->attachment->size,
                'bytes'      =>  number_format(ceil($this->attachment->size / 1024)) . ' Kb',
                'isImage'   =>  $this->attachment->isImage,
                'url'       =>  $this->attachment->url,
                'permalink' =>  $this->permalink
            )));
        }

        // 分片上传中，返回成功
        $this->response->throwJson(array('status' => 'chunk_uploaded', 'chunk' => $chunkIndex, 'chunks' => $totalChunks));
    }

    /**
     * 初始化函数
     *
     * @access public
     * @return void
     */
    public function action()
    {
        if ($this->user->pass('contributor', true) && $this->request->isPost()) {
            $this->security->protect();
            $this->upload();
        } else {
            $this->response->setStatus(403);
        }
    }
}
