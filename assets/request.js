var $ = require("jquery");

var Request = new (function () {
    /**
     * @callback requestSuccess
     * @param {XMLHttpRequest} xhr
     * @param {Object} data
     */

    /**
     * @param {String} uri
     * @param {requestSuccess} callback
     * @param {String} method
     * @param {Object} data
     */
    function request(uri, callback, method, data)
    {
        var options = {
            url: uri,
            type: method == undefined ? "GET" : method,
            dataType: "json",
            beforeSend: function (xhr)
            {
                $(document).trigger("before.request", [xhr]);
            }
        };

        if (data instanceof FormData) {
            options.processData = false;
            options.contentType = false;



            options.xhr = function() {
                var uploadXhr = $.ajaxSettings.xhr();
                if(uploadXhr.upload){
                    uploadXhr.upload.addEventListener('progress',function(event)
                    {
                        $(document).trigger("progress.request", [event]);
                    }, false);

                }
                return uploadXhr;
            };
        }

        if (data !== undefined) {
            options.data = data;
        }

        var complete = null,
            fail = null,
            always = null;

        if (typeof callback == "function") {
            complete = callback;
        } else if (typeof callback == "object") {
            complete = callback.complete;
            fail = callback.fail;
            always = callback.always;
        }

        $.ajax(options)
            .done(function (result, textStatus, xhr)
            {
                xhr.url = this.url;
                if (complete) {
                    $(document).one("complete.request", complete);
                }

                $(document).trigger("complete.request", [
                    xhr,
                    result
                ]);
            })
            .fail(function (xhr, textStatus, errorThrown)
            {
                if (fail) {
                    $(document).one("error.request", fail);
                }

                $(document).trigger("error.request", [
                    xhr,
                    errorThrown
                ]);
            })
            .always(function (data, textStatus, xhr)
            {
                if (always) {
                    $(document).one("finally.request", always);
                }

                if (textStatus == 'success') {
                    $(document).trigger("finally.request", [
                        xhr,
                        data
                    ]);
                } else {
                    $(document).trigger("finally.request", [
                        data,
                        xhr
                    ]);
                }
            });
    }

    function form(form, callback)
    {
        var formData = new FormData(form[0]);

        var uri = form.attr('action');
        var method = form.attr('method');

        if (!uri) {
            uri = document.location.href;
        }

        if (!method) {
            method = "POST";
        }


        request(uri, callback,  method, formData);
    }

    // Public methods
    this.request = request;
    this.form = form;
})();

module.exports = Request;
