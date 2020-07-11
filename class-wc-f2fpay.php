<?php
/*
 * Plugin Name: WooCommerce支付宝当面付
 * Plugin URI: https://xylog.cn/2019/08/18/woocommerce-alipay-f2f.html
 * Description:WooCommerce支付宝当面付扫码支付插件
 * Version: 0.0.2
 * Author: 晓羽
 * Author URI:https://xylog.cn
 */
use Omnipay\Omnipay;
if (! defined ( 'ABSPATH' ))exit();

add_action( 'init',function(){
    if( !class_exists('WC_Payment_Gateway') )  return;
    
    require_once(dirname(__FILE__)."/vendor/autoload.php");
    
    class WCF2FGateway extends WC_Payment_Gateway {
        private $config;
        public function __construct() {
            $this->id = "wc_f2fpay";
            $this->supports = [
                'refunds'
            ];
            $this->icon =plugin_dir_url(__FILE__).'alipay.png';
            $this->has_fields = false;
            $this->method_title = '支付宝当面付';
            $this->method_description='支付宝当面付扫码支付';
            $this->init_form_fields ();
            $this->init_settings ();
            $this->title = $this->get_option ( 'title' );
            $this->description = $this->get_option ( 'description' );
            add_filter('woocommerce_payment_gateways',array($this,'_add_gateway' ),10,1);
            add_action( 'wp_ajax_wc_f2fpay_orderquery', array($this, "get_order_status" ) );
            add_action( 'wp_ajax_nopriv_wc_f2fpay_orderquery', array($this, "get_order_status") );
            add_action( 'woocommerce_receipt_wc_f2fpay', array($this, 'receipt_page'));
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array ($this,'process_admin_options') );
            if(isset($_REQUEST['f2fpay_notify']))$this->notify();
        }
        /*工具类函数*/
        
        /*
         * 20位订单号生成函数
         * @param string $prefix    三位前缀
         * @param string $data        数据
         * @param string $length    数据长度
         * @return string    指定长度订单号，前缀-随机部分-数据
         */
        static function createID($prefix="XBP",$data="000000",$length=20){
            $prfxlen=strlen($prefix);
            $datalen=strlen($data);
            if($datalen>9)throw new Error("data too long");
            if($prfxlen>3)throw new Error("prefix too long");
            $random=str_replace(".","",uniqid("",true));
            $randlen=$length-$datalen-$prfxlen-1;
            $random=substr($random,6,$randlen);
            return $prefix.$datalen.$random.$data;
        }
        /*
         * 20位订单号解析函数
         * @param string $str    订单号
         * @return array [prefix,data]
         */
        static function parseID($str){
            $prefix=substr($str,0,3);
            $datalen=substr($str,3,1);
            $data=substr($str,strlen($str)-$datalen,$datalen);
            return [$prefix,$data];
        }
        /**
         * 生成订单标题
         * @param WC_Order $order
         * @param number $limit
         * @param string $trimmarker
         */
        public function get_order_title($order,$limit=32,$trimmarker='...'){
            $id = method_exists($order, 'get_id')?$order->get_id():$order->id;
            $title="#{$id}|".get_option('blogname');
            $order_items =$order->get_items();
            if($order_items&&count($order_items)>0){
                $title="#{$id}|";
                $index=0;
                foreach ($order_items as $item_id =>$item){
                    $title.= $item['name']." ";
                    if($index++>0){
                        $title.='...';
                        break;
                    }
                }    
            }
            
            return apply_filters('wcf2f_wc_get_order_title', mb_strimwidth ( $title, 0,32, '...','utf-8'));
        }
        /*
         * 创建Omnipay对象
         * @return OmnipayGateway
         */
        function createGateway(){
            $gateway = Omnipay::create('Alipay_AopF2F');
            $gateway->setSignType('RSA2');
            if($this->get_option( 'use_sandbox' )=="yes")$gateway->sandbox();
            $gateway->setAppId($this->get_option( 'app_id' ));
            $gateway->setPrivateKey($this->get_option('rsa_private_key'));
            $gateway->setAlipayPublicKey($this->get_option('ali_public_key'));
            $gateway->setNotifyUrl(get_bloginfo("url")."?f2fpay_notify=1");
            return $gateway;
        }
        /*
         * Woocommerce后台页面
         * @return array
         */
        public function init_form_fields() {
                $this->form_fields = [
                    'title' => array(
                        'title'       => "标题",
                        'type'        => 'text',
                        'description' => "",
                        'default'     => "支付宝当面付",
                        'desc_tip'    => true,
                    ),
                    'description' => array(
                        'title'       => "描述",
                        'type'        => 'textarea',
                        'description' => "",
                        'default'     => '支付宝付款',
                        'desc_tip'    => true,
                    ),
                    'use_sandbox' => array(
                        'title'   => '使用支付宝沙盒',
                        'type'    => 'checkbox',
                        'label'   => '使用支付宝沙雕（划掉）环境测试',
                        'default' => 'yes'
                    ),
                    'app_id' => array(
                        'title'       => '当面付APPID',
                        'type'        => 'text',
                        'description' => '',
                        'default'     => '',
                        'desc_tip'    => true,
                    ),
                    'ali_public_key' => array(
                        'title'       => '支付宝公钥',
                        'type'        => 'textarea',
                        'description' => '',
                        'default'     => '',
                        'desc_tip'    => true,
                    ),
                    'rsa_private_key' => array(
                        'title'       => '商户密钥',
                        'type'        => 'textarea',
                        'description' => '',
                        'default'     => '',
                        'desc_tip'    => true,
                    )
                ];
        }
        /*
         * Woocommerce付款处理
         * @return array
         */
        public function process_payment($order_id) {
            $order = new WC_Order ( $order_id );
            $order->reduce_order_stock();
            WC()->cart->empty_cart();
            return array (
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url ( true )
            );
        }
        public  function _add_gateway( $methods ) {
            $methods[] = $this;
            return $methods;
        }
        public function get_order_status() {
            $order_id = isset($_POST ['orderId'])?$_POST ['orderId']:'';
            $order = new WC_Order ( $order_id );
            $isPaid = ! $order->needs_payment ();
        
            echo json_encode ( array (
                'status' =>$isPaid? 'paid':'unpaid',
                'url' => $this->get_return_url ( $order )
            ));
            
            exit;
        }
        
        /**
         * 异步通知
         */
        public function notify() {
            if(defined('WP_USE_THEMES')&&!WP_USE_THEMES){
                return;
            }
            /*Wordpress转义$_POST会导致验签失败，坑*/
            $_POST = array_map( 'stripslashes_deep', $_POST );
            /*ZFB会把你的get参数post回来，坑*/
            unset($_POST['f2fpay_notify']);
            /*fund_bill_list https://github.com/lokielse/omnipay-alipay/issues/143*/
            $_POST['fund_bill_list'] AND $_POST['fund_bill_list'] = html_entity_decode($_POST['fund_bill_list']);
            /*虚假的log*/
            //file_put_contents(dirname(__FILE__)."/alipay82.log",http_build_query($_POST));
            
            $gateway=$this->createGateway();
            $request = $gateway->completePurchase();
            $request->setParams($_POST);
            try {
                $response = $request->send();
                if($response->isPaid()){
                    $this->afterNotify($response->data('out_trade_no'));
                    //file_put_contents("alipay89.log",print_r($response,true));
                    die('success');
                }else{
                    //file_put_contents("alipay83.log",print_r($response,true));
                    die('fail');
                }
            } catch (Exception $e) {
                //file_put_contents("alipay83.log",print_r($e,true));
                die('fail');
            }
        }
        public function afterNotify($trade_no){
            $order_id=$this->parseID($trade_no)[1];
            $order = new WC_Order ($order_id);
            if($order->needs_payment()){
                $order->payment_complete($trade_no);
            }
        }
        
        /**
         * 退款
         * @param int    $order_id
         * @param float  $amount
         * @param string $reason
         */
        public function process_refund( $order_id, $amount = NULL, $reason = '' ) {
            if(!$amount||$amount<=0){
                return new WP_Error("wcf2f","错误：退款金额应大于0");
            }
            $order = new WC_Order ($order_id);
            $gateway=$this->createGateway();
            $request = $gateway->refund();
            $request->setBizContent([
                'out_trade_no' => $order->get_transaction_id(),
                'refund_amount' => $amount,
                'refund_reason' => $reason==''?"商家退款":$reason
            ]);
            try {
                $response = $request->send();
                if($response->isSuccessful()){
                    return true;
                }else{
                    $aliResp = $response->getData()['alipay_trade_refund_response'];
                    return new WP_Error("wcf2f","退款失败：".$aliResp['sub_msg']);
                }
            } catch (Exception $e) {
                return new WP_Error("wcf2f","错误：退款异常：".$e->getMessage());
            }
        }
        /**
         * 主支付页面
         * @param int $order_id
         */
        function receipt_page($order_id) {
            $order = new WC_Order($order_id);
            if(!$order||!$order->needs_payment()){
                wp_redirect($this->get_return_url($order));
                exit;
            }
                global $woocommerce;
                $order = wc_get_order( $order_id );
                date_default_timezone_set('Asia/Shanghai');
                $gateway=$this->createGateway();
                $request = $gateway->purchase();
                $request->setBizContent([
                    'subject'    => $this->get_order_title($order),
                    'out_trade_no'    => $this->createID("WOO",$order_id),
                    'total_amount'    => $order->get_total()
                ]);
                
                try {
                    $response = $request->send();
                    $qrCodeContent = $response->getQrCode();
                } catch (Throwable $e) {
                    echo nl2br(print_r($e,true));
                }
            $url =isset($qrCodeContent)?$qrCodeContent:'';
            ?>
            <center>
                <h3 id="wcf2f-title">请用支付宝扫码付款</h3>
                <div style="width:200px;height:200px" id="wcf2f-qr-box">
                    <div id="wcf2f-qr-img" style="width:100%;height:100%;"></div>
                </div>
            <?php if(wp_is_mobile()){
                    echo '<a href="'.$url.'" target="_blank" class="button alt" style="width: 200px;border-radius: 0;height: 40px;line-height: 40px;padding: 0;" rel="nofollow">打开支付宝APP支付</a></center>';
             } ?>
            <script src="<?php echo plugin_dir_url(__FILE__);?>qrcode.js"></script>
            <script>
                jQuery(function(){
                    function queryOrderStatus() {
                        jQuery.ajax({
                            type: "POST",
                            url: wc_checkout_params.ajax_url,
                            data: {
                                orderId: "<?php echo $order_id;?>",
                                action: "<?php echo $this->id;?>_orderquery"
                            }
                        }).done(function (data) {
                            data = JSON.parse(data);
                            if (data && data.status === "paid") {
                                jQuery("#wcf2f-title").html("支付成功，正在处理");
                                location.href = data.url;
                            } else {
                                setTimeout(queryOrderStatus, 5000);
                            }
                        });
                    }
                    var qrcode = new QRCode(document.getElementById('wcf2f-qr-img'), {
                        width : 200,
                        height : 200
                    });
                    qrcode.makeCode("<?php echo $url;?>");
                    queryOrderStatus();
                });
            </script>
            <?php
        }
    }
    global $WCF2FAPI;
    $WCF2FAPI = new WCF2FGateway();
});
?>
