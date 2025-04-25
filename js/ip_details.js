// 定义必要的辅助函数
function ipdetails_showSpinner() { 
    $('div.loading').show(); 
}
function ipdetails_hideSpinner() { 
    $('div.loading').fadeOut('fast'); 
}
function ipdetails_showError(errorText) {
    $('div.jqueryError').fadeIn('fast');
    if(errorText.length>0) { 
        $('.jqueryErrorText').html(errorText).show(); 
    }
    ipdetails_hideSpinner();
}

$(document).ready(function() {
    // IP详情查看处理程序
    $(document).on("click", ".ip_details", function() {
        ipdetails_showSpinner();
        var id       = $(this).attr('data-id');
        var subnetId = $(this).attr('data-subnetId');
        
        // 发送请求获取IP详情数据
        $.post('app/subnets/addresses/ip-details-popup.php', {id:id, subnetId:subnetId}, function(data) {
            // 将响应数据放入弹窗
            $('#popupOverlay2 div.popup_w400').html(data);
            // 显示弹窗
            $('#popupOverlay2').fadeIn('fast');
            $('#popupOverlay2 .popup_w400').fadeIn('fast');
            // 禁用页面滚动
            $('body').addClass('stop-scrolling');
            // 隐藏加载图标
            ipdetails_hideSpinner();
        }).fail(function(jqxhr, textStatus, errorThrown) { 
            ipdetails_showError(jqxhr.statusText + "<br>Status: " + textStatus + "<br>Error: "+errorThrown);
            ipdetails_hideSpinner();
        });   
        
        return false;
    });
    
    // 关闭IP详情弹窗
    $(document).on("click", ".hidePopup2", function() {
        $('#popupOverlay2').fadeOut('fast');
        $('#popupOverlay2 .popup').fadeOut('fast');
        $('body').removeClass('stop-scrolling');
        return false;
    });
    
    // 复制IP详情内容处理程序
    $(document).on("click", ".copy-details", function() {
        var content = $("#ip-details-content").text();
        // 创建临时文本区域
        var textarea = document.createElement('textarea');
        textarea.value = content;
        document.body.appendChild(textarea);
        textarea.select();
        
        try {
            document.execCommand('copy');
            $(this).html('<i class="fa fa-check"></i> 已复制');
            setTimeout(function(){
                $(".copy-details").html('<i class="fa fa-copy"></i> 复制');
            }, 2000);
        } catch (err) {
            console.error('复制失败');
        }
        
        document.body.removeChild(textarea);
        return false;
    });
}); 