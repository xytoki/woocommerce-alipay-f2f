# woocommerce-alipay-f2f

woocommercealipay f2f payment gateway.

woocommerce支付宝当面付插件。

Please go to the Releases page to get the zip file.

下载完整包请到Releases页面。

[发布地址及简介](https://xylog.cn/2019/08/18/woocommerce-alipay-f2f.html)

## 常见问题
- 无法收到回调通知怎么办？  
可以尝试解除注释插件代码中的`file_put_contents`相关代码，并观察插件文件夹中产生的log文件。

-遇到签名验证失败怎么办？  
请检查支付宝的加密方式是否是RSA2，使用RSA1可能出现失败现象，详见 [#4](https://github.com/xytoki/woocommerce-alipay-f2f/issues/4#issuecomment-659952165)
