<?php
class FooController extends Controller
{
    /*
    12345678
    123456789
    sdfsdfdsfdgdfgdfgd
    */
    
    public function accessRules()
    {
    	return array(
            array('allow',
                'actions'=>array('notifyAlipay'),
                'users'=>array('*'),
            ),
        );
    }
    
    public function actionChongzhi($source="",$orderId="",$userId="")
    {
        $order = Translate::model()->findByPk($orderId,"user_id=:user_id",array(":user_id"=>Yii::app()->user->id));
        if($order===null || 0 >= $order->price || "paid" == $order->status)
            throw new CHttpException(404,'The requested page does not exist.');

        $user = $this->loadModel(Yii::app()->user->id);
        if($userId !== $user->email){
            throw new CHttpException(400,'The requested page does not exist.');
        }

        $model=new ChongzhiForm;
        $model->money = $order->price - $user->balance;
        if(isset($_POST['ChongzhiForm']))
        {
            $model->attributes=$_POST['ChongzhiForm'];
            if($model->validate()){
                $charge_info = $model->attributes;
                if($charge_info['money'] < $model->money)
                    throw new CHttpException(400,'支付失败！');

                $charge_info['charge_time'] = time();
                $charge_info['user_id'] = $user->id;

                $alipay = Yii::app()->alipay;
                // If starting a guaranteed payment, use AlipayGuaranteeRequest instead
                $request = new AlipayDirectRequest();
                $charge = new Charge;
                $charge->recharge_way = $charge_info['recharge_way'];
                $charge->user_id = $user->id;
                $charge->save(false);
                $charge_info['id'] = $charge->primaryKey; // Prints the last id.
				
				if("alipay" !== $charge_info['recharge_way']) {
					$request->paymethod = "bankPay";
					$request->defaultbank = $charge_info['recharge_way'];
				}
				
				$request->out_trade_no = $order->type . '-' . $order->id . '-' . $charge_info['id'];
                $request->subject = "[译点通专业翻译]充值订单号：" . $charge_info['id'];
                $request->body = "充值" . number_format($charge_info['money'],2) . "元";
                $request->total_fee = $charge_info['money'];
                //var_dump($request);exit();
                // Set other optional params if needed
                $form = $alipay->buildForm($request);
                echo $form;
                exit();
            }
        }

		$this->render('chongzhi',array(
            'model'=>$model,
            'order'=>$order,
            'user'=>$user,
		));
    }

    // Server side notification
    public function actionNotifyAlipay() {
        $alipay = Yii::app()->alipay;
        if ($alipay->verifyNotify()) {
            $order_id = $_POST['out_trade_no'];
            $order_fee = $_POST['total_fee'];
            if($_POST['trade_status'] == 'TRADE_FINISHED' || $_POST['trade_status'] == 'TRADE_SUCCESS') {
                if (strpos($order_id, "charge") === false)
                    $this->updateOrderStatus($order_id, $order_fee, $_POST['trade_status']);
                else
                    $this->chargeAccount($order_id, $order_fee, $_POST['trade_status']);
                    
                echo "success";
            }
            else {
                echo "success";
            }
        } else {
            $this->delete_order_record($order_id);
            //echo "fail";
            throw new CHttpException(404,'交易失败！');
            exit();
        }
    }

    //Redirect notification
    public function actionReturnAlipay() {
        $alipay = Yii::app()->alipay;
        if ($alipay->verifyReturn()) {
            $order_id = $_GET['out_trade_no'];
            $order_fee = $_GET['total_fee'];

            if($_GET['trade_status'] == 'TRADE_FINISHED' || $_GET['trade_status'] == 'TRADE_SUCCESS') {
                if (strpos($order_id, "charge") === false)
                    $this->updateOrderStatus($order_id, $order_fee, $_GET['trade_status']);
                else
                    $this->chargeAccount($order_id, $order_fee, $_GET['trade_status']);

                if(empty($this->_message))
                    $this->_message = "恭喜您，交易成功！";
                $this->eUserFlash($this->_message);
                $user = User::model()->findByPk(Yii::app()->user->id);
                $this->render('/user/panel',array('user'=>$user));
            }
            else {
                echo "trade_status=".$_GET['trade_status'];
            }
        } else {
            $this->delete_order_record($order_id);
            //echo "fail";
            throw new CHttpException(404,'交易失败！');
            exit();
        }
    }

    public function updateOrderStatus($order_id, $total_fee, $trade_status)
    {
        $transaction=Yii::app()->db->beginTransaction();
        try{
            $arr_order_id = explode("-",$order_id);

            $orderId = $arr_order_id[1];
            $order = Translate::model()->findByPk($orderId);
            if($order===null)
                throw new CHttpException(404,'交易失败！订单号不存在。');

            $chargeId = $arr_order_id[2];
            $charge = Charge::model()->findByPk($chargeId);
            if($charge===null)
  	        throw new CHttpException(404,'交易失败！交易号不存在。');

            if(0 >= $charge->money && empty($charge->charge_time))
            {
            	$charge->chargetype = 1;
            	$charge->money = $total_fee;
            	$charge->charge_time = time();
            	$charge->recharge_way = 'alipay';
                if($charge->save(false))
                {
                    $user = $this->loadModel($charge->user_id);
                    if($order->price <= ($user->balance + $total_fee))
                    {
                        $user->balance = $user->balance + $total_fee - $order->price;
                        if($user->save(false))
                        {
                            $charge = new Charge;
                            $charge->chargetype = 0;
                            $charge->money = $order->price;
                            $charge->recharge_way = "";
                            $charge->translate_id = $order->id;
                            $charge->user_id = $user->id;
                            $charge->charge_time = time();
                            //var_dump($charge);exit();
                            $charge->save(false);

                            $order->status = paid;
                            $order->save(false);
                    	    $this->_message = '订单支付成功！';
                        }
                    } else {
                        $user->balance += $total_fee;
                        $user->save(false);

                	    $this->_message ='余额不足以支付订单！充值金额：' . number_format($total_fee,2) . "元";
                    }
                }
            }
            $transaction->commit(); //提交事务会真正的执行数据库操作
        } catch (Exception $e) {
            $transaction->rollback();//如果操作失败, 数据回滚
        }
    }

    public function chargeAccount($order_id, $total_fee, $trade_status)
    {
        $transaction=Yii::app()->db->beginTransaction();
        try{
            $charge = Charge::model()->findByPk(substr($order_id,7));
            if($charge===null)
			    throw new CHttpException(404,'交易失败！交易号不存在。');

            if(0 >= $charge->money && empty($charge->charge_time))
            {
            	$charge->chargetype = 1;
            	$charge->money = $total_fee;
            	$charge->charge_time = time();
            	$charge->recharge_way = 'alipay';
            	if($charge->save(false))
            	{
           	        $user = User::model()->findByPk($charge->user_id);
    	            $user->balance += $total_fee;
                    $user->save(false);

            	    $this->_message = '充值成功！';
            	}
            }
            $transaction->commit(); //提交事务会真正的执行数据库操作
        } catch (Exception $e) {
            $transaction->rollback();//如果操作失败, 数据回滚
        }
    }
?>
