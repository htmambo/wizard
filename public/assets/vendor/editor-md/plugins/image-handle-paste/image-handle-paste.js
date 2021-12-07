/*!
 * editormd图片粘贴上传插件
 *
 * @file   image-handle-paste.js
 * @author codehui
 * @date   2018-11-07
 * @link   https://www.codehui.net
 */
(function() {
    var factory = function (exports) {
        var $            = jQuery;           // if using module loader(Require.js/Sea.js).
        var pluginName   = "image-handle-paste";  // 定义插件名称
        //图片粘贴上传方法
        exports.fn.imagePaste = function() {
            var _this       = this;
            var cm          = _this.cm;
            var settings    = _this.settings;
            var editor      = _this.editor;
            var classPrefix = _this.classPrefix;
            var id          = _this.id;
            if(!settings.imageUpload || !settings.imageUploadURL){
                console.log('你还未开启图片上传或者没有配置上传地址');
                return false;
            }
            //监听粘贴板事件
            $('#' + id).on('paste', function (e) {
                var items = (e.clipboardData || e.originalEvent.clipboardData).items;
                //判断图片类型
                if (items && items[0].type.indexOf('image') > -1) {
                    var file = items[0].getAsFile();
                    /*生成blob
                    var blobImg = URL.createObjectURL(file);
                    */
                    /*base64
                    var reader = new FileReader();
                    reader.readAsDataURL(file);
                    reader.onload = function (e) {
                        var base64Img = e.target.result //图片的base64
                    }
                    */
                    // 创建FormData对象进行ajax上传
                    var forms = new FormData(document.forms[0]); //Filename
                    forms.append(classPrefix + "image-file", file, "file_"+Date.parse(new Date())+".png"); // 文件
                    _this.executePlugin("imageDialog", "image-dialog/image-dialog");
                    _ajax(settings.imageUploadURL, forms, function(ret){
                        if(ret.success == 1){
                            var dialog = $("." + classPrefix + "image-dialog");
                            dialog.find("input[data-url]").val(ret.url);
                            // dialog.find('input[data-alt]').val(ret.url.split('/').pop());
                            //cm.replaceSelection("![](" + ret.url  + ")");
                        }
                        console.log(ret.message);
                    });
                }
                else if ($.inArray("text/html", (e.clipboardData || e.originalEvent.clipboardData).types) != -1) {
                    var htmlText = (e.clipboardData || e.originalEvent.clipboardData).getData("text/html");
                    if (htmlText !== "") {
                        var referencelinkRegEx = /reference-link/;
                        _this.insertValue(toMarkdown(htmlText, {
                            gfm: true,
                            converters:[
                                {
                                    filter: 'br',
                                    replacement: function(content) {
                                        return "  \n";
                                    }
                                },
                                {
                                    filter: 'code',
                                    replacement: function(content) {
                                        return "\n```\n" + content + "\n```\n";
                                    }
                                },
                                {
                                    filter: 'div',
                                    replacement: function(content) {
                                        return content + '\n';
                                    }
                                },
                                {
                                    filter: 'section',
                                    replacement: function(content) {
                                        return content + '\n';
                                    }
                                },
                                {
                                    filter: 'span',
                                    replacement: function(content) {
                                        return content;
                                    }
                                },
                                {
                                    filter:'var',
                                    replacement:function(content){
                                        return '`' + content + '`';
                                    }
                                },
                                {
                                    filter:'caption',
                                    replacement:function(content){
                                        return content + '  \n';
                                    }
                                },
                                {
                                    filter: function (node) {
                                        return (node.nodeName === 'A' && referencelinkRegEx.test(node.className));
                                    },
                                    replacement: function(content) {
                                        return "";
                                    }
                                }]})
                        );
                        e.preventDefault();
                    }
                }
            })
        };
        // ajax上传图片 可自行处理
        var _ajax = function(url, data, callback) {
            $.ajax({
                "type": 'post',
                "cache": false,
                "url": url,
                "data": data,
                "dateType": "json",
                "processData": false,
                "contentType": false,
                "mimeType": "multipart/form-data",
                success: function(ret){
                    callback(JSON.parse(ret));
                },
                error: function (err){
                    console.log('请求失败')
                }
            })
        }
    };
    // CommonJS/Node.js
    if (typeof require === "function" && typeof exports === "object" && typeof module === "object")
    {
        module.exports = factory;
    }
    else if (typeof define === "function")  // AMD/CMD/Sea.js
    {
        if (define.amd) { // for Require.js
            define(["editormd"], function(editormd) {
                factory(editormd);
            });
        } else { // for Sea.js
            define(function(require) {
                var editormd = require("./../../editormd");
                factory(editormd);
            });
        }
    }
    else
    {
        factory(window.editormd);
    }
})();