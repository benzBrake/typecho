<?php

class Typecho_Compat
{
    /**
     * glob 兼容实现（支持 *.ext）
     *
     * @param string $pattern
     * @return array
     */
    public static function glob($pattern)
    {
        // 如果 glob 可用，优先用
        if (function_exists('glob')) {
            $result = @glob($pattern);
            if ($result !== false) {
                return $result;
            }
        }

        // fallback
        $files = array();

        $dir = dirname($pattern);
        $mask = basename($pattern);

        if (!is_dir($dir)) {
            return $files;
        }

        $ext = '';
        if (strpos($mask, '*.') === 0) {
            $ext = substr($mask, 2);
        }

        $handle = @opendir($dir);
        if (!$handle) {
            return $files;
        }

        while (false !== ($file = readdir($handle))) {
            if ($file == '.' || $file == '..')
                continue;

            if ($ext) {
                if (substr($file, -strlen($ext)) === $ext) {
                    $files[] = $dir . '/' . $file;
                }
            } else {
                $files[] = $dir . '/' . $file;
            }
        }

        closedir($handle);
        return $files;
    }
}