<?php if (!defined('__TYPECHO_ADMIN__')) exit; ?>
<?php
if (isset($post) && $post instanceof Typecho_Widget && $post->have()) {
    $fileParentContent = $post;
} else if (isset($page) && $page instanceof Typecho_Widget && $page->have()) {
    $fileParentContent = $page;
}

$phpMaxFilesize = function_exists('ini_get') ? trim(ini_get('upload_max_filesize')) : 0;

if (preg_match("/^([0-9]+)([a-z]{1,2})$/i", $phpMaxFilesize, $matches)) {
    $phpMaxFilesize = strtolower($matches[1] . $matches[2] . (1 == strlen($matches[2]) ? 'b' : ''));
}

$totalChunksMaxSize = '500m';
if (defined('__TYPECHO_MAX_CHUNKED_FILESIZE__')) {
    $totalChunksMaxSize = __TYPECHO_MAX_CHUNKED_FILESIZE__;
}
$totalChunksMaxSize = compareAndSelectSize($phpMaxFilesize, $totalChunksMaxSize);
$totalChunksMaxSize = rtrim($totalChunksMaxSize, 'b') . 'b';

function compareAndSelectSize($size1, $size2)
{
    // 如果无法解析第一个大小，则返回第二个作为默认值
    if (empty($size1)) {
        return $size2;
    }
    // 将大小统一转换为字节进行比较
    $bytes1 = returnBytes($size1);
    $bytes2 = returnBytes($size2);

    // 比较
    if ($bytes1 > $bytes2) {
        return $size1;
    } else {
        return $size2;
    }
}
/**
 * 将带单位的字符串（如 '8M', '1G'）转换为字节数
 *
 * @param string $val
 * @return int
 */
function returnBytes($val)
{
    $val = trim($val);
    $last = strtolower($val[strlen($val) - 1]);
    $val = (int)$val;
    switch ($last) {
        // 'g' -> gigabytes
        case 'g':
            $val *= 1024;
            // 'm' -> megabytes
        case 'm':
            $val *= 1024;
            // 'k' -> kilobytes
        case 'k':
            $val *= 1024;
    }
    return $val;
}
?>

<script src="<?php $options->adminStaticUrl('js', 'moxie.js?v=' . $suffixVersion); ?>"></script>
<script src="<?php $options->adminStaticUrl('js', 'plupload.js?v=' . $suffixVersion); ?>"></script>
<script>
    $(document).ready(function() {
        function updateAttacmentNumber() {
            var btn = $('#tab-files-btn'),
                balloon = $('.balloon', btn),
                count = $('#file-list li .insert').length;

            if (count > 0) {
                if (!balloon.length) {
                    btn.html($.trim(btn.html()) + ' ');
                    balloon = $('<span class="balloon"></span>').appendTo(btn);
                }

                balloon.html(count);
            } else if (0 == count && balloon.length > 0) {
                balloon.remove();
            }
        }

        $('.upload-area').bind({
            dragenter: function() {
                $(this).parent().addClass('drag');
            },

            dragover: function(e) {
                $(this).parent().addClass('drag');
            },

            drop: function() {
                $(this).parent().removeClass('drag');
            },

            dragend: function() {
                $(this).parent().removeClass('drag');
            },

            dragleave: function() {
                $(this).parent().removeClass('drag');
            }
        });

        updateAttacmentNumber();

        function fileUploadStart(file) {
            $('<li id="' + file.id + '" class="loading">' +
                file.name + '</li>').appendTo('#file-list');
        }

        function fileUploadError(error) {
            var file = error.file,
                code = error.code,
                word;

            switch (code) {
                case plupload.FILE_SIZE_ERROR:
                    word = '<?php _e('文件大小超过限制'); ?>';
                    break;
                case plupload.FILE_EXTENSION_ERROR:
                    word = '<?php _e('文件扩展名不被支持'); ?>';
                    break;
                case plupload.FILE_DUPLICATE_ERROR:
                    word = '<?php _e('文件已经上传过'); ?>';
                    break;
                case plupload.HTTP_ERROR:
                default:
                    word = '<?php _e('上传出现错误'); ?>';
                    break;
            }

            var fileError = '<?php _e('%s 上传失败'); ?>'.replace('%s', file.name),
                li, exist = $('#' + file.id);

            if (exist.length > 0) {
                li = exist.removeClass('loading').html(fileError);
            } else {
                li = $('<li>' + fileError + '<br />' + word + '</li>').appendTo('#file-list');
            }

            li.effect('highlight', {
                color: '#FBC2C4'
            }, 2000, function() {
                $(this).remove();
            });

            // fix issue #341
            this.removeFile(file);
        }

        var completeFile = null;

        function fileUploadComplete(id, url, data) {
            var li = $('#' + id).removeClass('loading').data('cid', data.cid)
                .data('url', data.url)
                .data('image', data.isImage)
                .html('<input type="hidden" name="attachment[]" value="' + data.cid + '" />' +
                    '<a class="insert" target="_blank" href="###" title="<?php _e('点击插入文件'); ?>">' + data.title + '</a><div class="info">' + data.bytes +
                    ' <a class="file" target="_blank" href="<?php $options->adminUrl('media.php'); ?>?cid=' +
                    data.cid + '" title="<?php _e('编辑'); ?>"><i class="i-edit"></i></a>' +
                    ' <a class="delete" href="###" title="<?php _e('删除'); ?>"><i class="i-delete"></i></a></div>')
                .effect('highlight', 1000);

            attachInsertEvent(li);
            attachDeleteEvent(li);
            updateAttacmentNumber();

            if (!completeFile) {
                completeFile = data;
            }
        }

        console.log('<?php echo $totalChunksMaxSize; ?>')

        var uploader = null,
            tabFilesEl = $('#tab-files').bind('init', function() {
                uploader = new plupload.Uploader({
                    browse_button: $('.upload-file').get(0),
                    url: '<?php $security->index('/action/upload-chunked'
                                . (isset($fileParentContent) ? '?cid=' . $fileParentContent->cid : '')); ?>',
                    runtimes: 'html5,flash,html4',
                    flash_swf_url: '<?php $options->adminStaticUrl('js', 'Moxie.swf'); ?>',
                    drop_element: $('.upload-area').get(0),
                    chunk_size: '<?php echo $phpMaxFilesize; ?>',
                    send_chunk_number: true,
                    multipart: true,
                    filters: {
                        max_file_size: '<?php echo $totalChunksMaxSize; ?>',
                        mime_types: [{
                            'title': '<?php _e('允许上传的文件'); ?>',
                            'extensions': '<?php echo implode(',', $options->allowedAttachmentTypes); ?>'
                        }],
                        prevent_duplicates: true
                    },

                    init: {
                        FilesAdded: function(up, files) {
                            for (var i = 0; i < files.length; i++) {
                                fileUploadStart(files[i]);
                            }

                            completeFile = null;
                            uploader.start();
                        },

                        UploadComplete: function() {
                            if (completeFile) {
                                Typecho.uploadComplete(completeFile);
                            }
                        },

                        FileUploaded: function(up, file, result) {
                            if (200 == result.status) {
                                var data = $.parseJSON(result.response);

                                if (data) {
                                    fileUploadComplete(file.id, data[0], data[1]);
                                    uploader.removeFile(file);
                                    return;
                                }
                            }

                            fileUploadError.call(uploader, {
                                code: plupload.HTTP_ERROR,
                                file: file
                            });
                        },

                        Error: function(up, error) {
                            fileUploadError.call(uploader, error);
                        }
                    }
                });

                uploader.init();
            });

        Typecho.uploadFile = function(file, name) {
            if (!uploader) {
                $('#tab-files-btn').parent().trigger('click');
            }

            var timer = setInterval(function() {
                if (!uploader) {
                    return;
                }

                clearInterval(timer);
                timer = null;

                uploader.addFile(file, name);
            }, 50);
        };

        function attachInsertEvent(el) {
            $('.insert', el).click(function() {
                var t = $(this),
                    p = t.parents('li');
                Typecho.insertFileToEditor(t.text(), p.data('url'), p.data('image'));
                return false;
            });
        }

        function attachDeleteEvent(el) {
            var file = $('a.insert', el).text();
            $('.delete', el).click(function() {
                if (confirm('<?php _e('确认要删除文件 %s 吗?'); ?>'.replace('%s', file))) {
                    var cid = $(this).parents('li').data('cid');
                    $.post('<?php $security->index('/action/contents-attachment-edit'); ?>', {
                            'do': 'delete',
                            'cid': cid
                        },
                        function() {
                            $(el).fadeOut(function() {
                                $(this).remove();
                                updateAttacmentNumber();
                            });
                        });
                }

                return false;
            });
        }

        $('#file-list li').each(function() {
            attachInsertEvent(this);
            attachDeleteEvent(this);
        });
    });
</script>