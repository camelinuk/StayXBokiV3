<?php 
/*
加入會員：
     由飯店提供一連結網址。
     讓顧客可以掃qrcode條碼，下載app後，再經由app加入會員。

忘記密碼：
     彈跳視窗出現：請至享福卡app確認。
     讓會員前往手機app做密碼更新作業。

登入會員：
步驟1傳送網址及Method如下：
測試POST：https://app10.e-giant.com.tw/pms_api/getMemberLevel.asp
*/

namespace Apv3\Models\Memb;

use Hybridauth\HttpClient;
use Hybridauth\Hybridauth;
use Hybridauth\Storage\Session as HybridSession;
use Apv3\Models\InfMemb as InfMemb;

class StayX10 implements InfMemb
{
	protected $aLogin,$Cid,$Hid,$Chlid,$Key,$CHANNEL_ID, $CHANNEL_SECRET;

	const TYPE = 'StayX10';
	
	protected $aUrl = array(
		'logi' => null,
		'forg' => null,
		'newa' => null,
		'edit' => null,
		'delt' => null,
	);
	public function __construct($cid,$hid,$chlid) {
		$this->Cid = (int)$cid;
		$this->Hid = (int)$hid;
		$this->Chlid = (int)$chlid;

		$res = \DB::table('Chl_Htls')
			->where('Chl_Htls.Cid', $this->Cid)
			->where('Chl_Htls.Hid', $this->Hid)
			->where('Chl_Htls.ChlId', $this->Chlid)
			->whereRaw("('".date('Y-m-d')."' between SDT AND EDT)")
			->select('LinKey','Memb','Config')
			// ->remember(30)
			->first();
		$getConf = json_decode($res->Config);
		$aMemb = json_decode($res->Memb);
		$this->aUrl = array(
			'logi' => $aMemb->AuthUrl,
			'forg' => $aMemb->ForgetUrl,
			'newa' => $aMemb->JoinUrl,
			'edit' => $aMemb->AuthUrl,
			'delt' => $aMemb->AuthUrl,
			'cart' => $aMemb->CartUrl,
			'token'=> $aMemb->Token
		);
		$this->Key = $res->LinKey;
		$this->CHANNEL_ID = !isset($getConf->loginID)?:$getConf->loginID;
		$this->CHANNEL_SECRET = !isset($getConf->loginSecret)?:$getConf->loginSecret;
	}
	/*

	若有查到會員，會傳回00
	POSTurl:https://vue3.stayx.vip/memb,act:chk,data:{"uid":"U885bcd8b7ffa0ac6d8a856dadb22fea1","nam":"\u61f6\u862d\u5152(YoYo\ud83d\ude1c)","pic":"https:\/\/profile.line-scdn.net\/0hAQ2KTb2NHn4fMgxRgUZgAW9iHRQ8Q0dsNgMFSHk3FBl1VVt7Y1EGTHo6FB0iAV19NwYFT38wFB4TIWkYAWTiShgCQ08jBl0sMFdZkA"}
	BACK{"rtn_message":"Query successful.","rtn_code":"00","timestamp":"20240801162218","rtn_result":{"id":2,"mbid":9999,"grade":"\u4e00\u822c\u6703\u54e1","rate":"0.00","firstname":"Yoyo","lastname":"Do","sex":"F","birthday":"0000-00-00","phone":"0912123123","idno":"A123456789","email":"yoyodo128@gmail.com","memo":null}}
	*/
	//會員登入
	public function doLogin($route,$email,$pw){
		$aT = $aC =  $aG =  $aCo = [];
		// var_dump($this->aUrl['logi'],$email,$pw);
		$switchMemb = \Apv3Helpers::switchChlMember($this->Cid,$this->Hid);
		$szChlHtls = \Apv3Helpers::szChlHtls('aChlHtls');
		$key = (isset($switchMemb->Cid)?$switchMemb->Cid:$szChlHtls['Cid']).'_'.(isset($switchMemb->Hid)?$switchMemb->Hid:$szChlHtls['Hid']).'_'.(isset($switchMemb->Chn)?$switchMemb->Chn:$szChlHtls['ChlId']).'_'.(isset($switchMemb->Key)?$switchMemb->Key:$szChlHtls['LinKey']);
		if(\Session::has('aHtlMemb.'.$key))
			return \Session::get('aHtlMemb.'.$key);
		try{
			// 存log
			\UserLog::BackenTbLog('StayX10', 'C' . $this->Cid . 'H' . $this->Hid, $this->Cid, $this->Hid, 0, 'POST'.$this->aUrl['logi'], __METHOD__, __LINE__);
			
			$curl = curl_init();

			curl_setopt_array($curl, array(
				CURLOPT_URL => $this->aUrl['logi'] .'?email='. $email .'&pw='. $pw,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => '',
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => 'POST',
				CURLOPT_HTTPHEADER => array(
					'Act: login',
					'Content-Type: application/json',
					'Authorization: Bearer '.$this->aUrl['token']
				),
			));

			$response = curl_exec($curl);

			curl_close($curl);

			\UserLog::BackenTbLog('StayX10', 'C' . $this->Cid . 'H' . $this->Hid, $this->Cid, $this->Hid, 0, 'BACK'.$response, __METHOD__, __LINE__);
			$res = json_decode($response);
		}catch (Exception $e){
			throw new \Exception('連線失效', 202);
		}
		//$cnt = '"Y","09211240004","null","null","null","null/null/null","null","0","0","0"';
		//$cnt = '"Y","09211240004","鄭怡宜","F","F203358501","1976/05/29","0920805865","4200","0","0"';
		//$cnt = '"Y","0900000123","群豐測試","M","","1976/01/01","0900000123","0","1000","0"';

		if($res->rtn_code == '02')	// 查詢失敗，查無此會員資料
			throw new \Exception($res->rtn_message, 202);
		else if ($res->rtn_code == '01')	// 查詢失敗，請提供必填資料
			throw new \Exception($res->rtn_message, 204);
		else if($res->rtn_code == '00'){
			$aRes = json_decode(json_encode($res),true)['rtn_result'];
			$grade = $aRes['grade'];	// 會員級別
			$aRes['mid'] = $aRes['mbid'];	// mbid為Htl_Memb.id, 以便後續判斷會員資格
			$aRes['Email'] = $email;
			$aRes['LoginPw'] = $pw;
			// 找出BOKI 對應會員級別及折扣
			
			$aMemb = \DB::table('Typ_Memb')
				->leftjoin('Dsc_Info AS Di', 'Di.RegApi','=','Typ_Memb.id')
				->select('Typ_Memb.WebName','Typ_Memb.Code','Typ_Memb.DiscCal')
				->where('Typ_Memb.Cid',$this->Cid)
				->where('Typ_Memb.Hid',$this->Hid)
				->where('Typ_Memb.Active','Y')
				->where('Di.Active','Y')
				->where('Typ_Memb.Code',$grade)
				->first();
			// dd($aRes,$aMemb);
			// 把會員登入資訊存在Session，重整頁面後無需再次登入
			// \Session::put('aHtlMemb.'.$key, array_merge($aRes,$aMemb->toArray()));
			$aC = array(
				'Type' 		=> 'B',
				'from'		=> 'StayX',	// StayX會員
				'Route' 	=> 'StayX',
				'id'		=> $aRes['mbid'],	// BOKI 會員id
				'aC' => array(
					'c_ssotyp'	=> 'StayX',	// StayX會員，存在Ord_Cust.SsoTyp
					'c_ssouid'	=> $aRes['id'],	// 記錄StayX會員號，存在Ord_Cust.SsoUid
					'c_fname'	=> $aRes['firstname'],
					'c_lname' 	=> $aRes['lastname'],
					'c_gender' 	=> $aRes['sex'],
					'c_bd' 		=> !empty($aRes['sex'])?$aRes['sex']:'',
					'c_phone' 	=> $aRes['phone'],
					'c_idnum' 	=> !empty($aRes['idno'])?$aRes['idno']:'',
					'c_passnum' => '',
					'c_email' 	=> $aRes['email'],
					'c_orderpw'	=> $pw,
					'memo' 		=> (!empty($aRes['memo'])?$aRes['memo'].',':'').(intval($aRes['rate']) != 0?'NO.'.$aRes['id'].',Discount:'.$aRes['rate'].'%':''),//住房備註
					'c_edm' 	=> false,//會員電子報 
					'c_coupon' 	=> false,//好康優惠券
					'c_country' => 'TW',//國籍
					'LineId' 	=> true,	//Stayx會員LineId一律回傳true，但不回傳ID號碼
					'LRId' 		=> null,	//Stayx會員LRId一律回傳false，但不回傳ID號碼
					'LRUrl' 	=> null,	//LRUrl
				));
			if(!is_null($aMemb)){
				// 把符合折扣條件存在Session裡
				$aMemb = json_decode(json_encode($aMemb),true);
				$aC = array_merge($aC,$aRes,$aMemb);
				\Cookie::queue($key, array_merge($aRes,$aMemb,$aC), 10); // 10分鐘
			}
		}else{
			throw new \Exception('member login fail',210);
		}
		return $aC;
	}
	//新增或修改Boki會員
	/**
	 * 
	 * response
	 * {
	 *     "rtn_message": "Save failed (Account already registered).",
	 *     "rtn_code": "03",
	 *     "timestamp": "20240527112622",
	 *     "rtn_result": {
	 *         "id": 2
	 *     }
	 * }
	 */
	public function _doAddOrUpd($Route,$SoId,$LName,$FName,$BthDay,
		$Mob,$Email,$Gender,$Contry,$City,$Paspot,$IdNo,$Memo,$LogPw,$mid=null){
			$Csn = $this->Cid;
			$Hsn = $this->Hid;
			$Chn = $this->Chlid;
			$Key = $this->Key;
			$Paspot = empty($Paspot)?NULL:substr($Paspot,0,16);
			$IdNo 	= empty($IdNo)?NULL:substr($IdNo,0,16);
			$LogPw 	= substr($LogPw,0,16);
			$Email 	= substr($Email,0,64);
			$Memo 	= substr($Memo,0,256);
			$RcvEdm = 'N';
			$RcvCpn = 'N';
			$RcvIp  = null;
			$BthDay = empty($BthDay)?'0000-00-00':$BthDay;

			$pdo = \DB::connection()->getPdo();
			//Works fine in my local machine (Win 7 + XAMPP) without the following line,
			//	but didn't work in my server. So better have the following line
			if( strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN' ){
				$pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
			}
			////-- call membJoin(3,9,2,'PXK9CQ', 'Chen','James','1974-08-29','0988123123','james.chen@lemon.cx','M','TW','','E121736779','memo','sword1234','Y','Y','');
			if(empty($mid))
				$mid = 0;
			$updid 	= 0;
			\UserLog::BackenTbLog('modfMemb', $Csn . '_' . $Hsn, $Csn, $Hsn, 0, "call modfMemb( " . $mid . ", " . $Csn . ", " . $Hsn . ", " . $Chn . ", '" . $Key . "', '" . $LName . "', '" . $FName . "', '" . $BthDay . "', '" . $Mob . "', '" . $Email . "', '" . $Gender . "', '" . $Contry . "', '" . $City . "', '" . $Paspot . "', '" . $IdNo . "', '" . $Memo . "', '" . $LogPw. "', '" . $RcvEdm. "', '" . $RcvCpn. "', '" . $RcvIp . "', " . $updid . ");", __METHOD__, __LINE__);
			$stmt = $pdo->prepare("call modfMemb(:mid, :cid, :hid, :chlid, :chKey, :LName, :FName, :BthDay, :Mob, :Email, :Gender, :Contry, :City, :Paspot, :IdNo, :Memo, :LogPw, :RcvEdm, :RcvCpn, :RcvIp, :UpdBy)");
			$stmt->bindParam(':mid',  	$mid, 	\PDO::PARAM_INT);
			$stmt->bindParam(':cid', 	$Csn, 	\PDO::PARAM_INT);
			$stmt->bindParam(':hid', 	$Hsn, 	\PDO::PARAM_INT);
			$stmt->bindParam(':chlid', 	$Chn,	\PDO::PARAM_INT);
			$stmt->bindParam(':chKey', 	$Key,	\PDO::PARAM_STR, 32);
			$stmt->bindParam(':LName', 	$LName,	\PDO::PARAM_STR, 64);
			$stmt->bindParam(':FName', 	$FName,	\PDO::PARAM_STR, 64);
			$stmt->bindParam(':BthDay', $BthDay,\PDO::PARAM_STR, 10);
			$stmt->bindParam(':Mob', 	$Mob,	\PDO::PARAM_STR, 16);
			$stmt->bindParam(':Email', 	$Email,	\PDO::PARAM_STR, 64);
			$stmt->bindParam(':Gender',	$Gender,\PDO::PARAM_STR, 1);
			$stmt->bindParam(':Contry', $Contry,\PDO::PARAM_STR, 2);
			$stmt->bindParam(':City',   $City,  \PDO::PARAM_STR, 4);
			$stmt->bindParam(':Paspot', $Paspot,\PDO::PARAM_STR, 16);
			$stmt->bindParam(':IdNo',	$IdNo,	\PDO::PARAM_STR, 16);
			$stmt->bindParam(':Memo', 	$Memo,	\PDO::PARAM_STR, 256);
			$stmt->bindParam(':LogPw', 	$LogPw,	\PDO::PARAM_STR, 16);
			$stmt->bindParam(':RcvEdm',	$RcvEdm,\PDO::PARAM_STR, 1);
			$stmt->bindParam(':RcvCpn',	$RcvCpn,\PDO::PARAM_STR, 1);
			$stmt->bindParam(':RcvIp',	$RcvIp,	\PDO::PARAM_STR, 16);
			$stmt->bindParam(':UpdBy',	$updid,	\PDO::PARAM_STR, 16);
			
			$stmt->execute();
			$results = array();
			try{
				do {
					// 共回傳五筆資料，所以，要確定回傳的筆數，不然，會造成錯誤
					foreach( $stmt->fetchAll( \PDO::FETCH_ASSOC ) as $row ){
						$results[] = $row;
						//if($results[0]['ErrNo']!=0){$do=0;break;}
					}
				} while ( $stmt->nextRowset() );
			}catch(\PDOException $e){
				$results[0]['PDoErr'] = $e;
				// dd('已是會員，要找回原會員編號?');
			}
			// dd($results);
			/*
			| ErrNo : 0 , No Error
			|		: 100, Expired in Chl_Hotl
			|		: 110, Typ_Memb Not SET
			|		: 120, Member Register duplicate email.
			| EffNum : 0, Insert Fail
			| 		: 1, Insert One Row
			*/
			$aRM['ErrNo'] = $results[0]['ErrNo'];
			$aRM['EffNum'] = $results[0]['EffNum'];
			$data = array();

			$aRM['EffRoute'] = $Route;
			if($aRM['ErrNo'] == 120){
				// 已存在會員，會員id為$aRM['EffNum']
				$mid = $aRM['EffNum'];
				// dd('已存在會員，會員id為',$aRM['EffNum']);
				// return $aRM['ErrNo'];
			}else{
				// 新增的會員直接寫入已驗証
				if($mid == 0)
					\DB::table('Htl_Memb')
							->where('id', $aRM['EffNum'])
							->update(array('Verified'=>'Y','LineId'=>$SoId));  
				else{
					// 寫入line 資訊
					$LineMb = \Session::get('StayX.data');
					\DB::table('Htl_Memb')
					->where('id', $mid)
					->update(array('LineId'=>$LineMb['uid'],'LineName'=>$LineMb['nam'],'LinePhoto'=>$LineMb['pic']));  
				}

			}
			// dd($aRM);
			return $aRM;
	}
	//加入會員
	/**
	 * 1.先存boki會員，
	 * 2.取得mid之後發送至StayX建立會員
	 * 
	 * response
	 * {
	 *     "rtn_message": "Save failed (Account already registered).",
	 *     "rtn_code": "03",
	 *     "timestamp": "20240527112622",
	 *     "rtn_result": {
	 *         "id": 2
	 *     }
	 * }
	 */
	public function doJoin($Route,$SoId,$LName,$FName,$BthDay,
	$Mob,$Email,$Gender,$Contry,$City,$Paspot,$IdNo,$Memo,$LogPw){
		// 1.先存boki會員
		$rtn = $this->_doAddOrUpd(StayX10::TYPE,			// $Route,
				\Session::get('StayX.data')['uid'],			// $SoId,
				$LName,			// $LName
				$FName,			// $FName
				$BthDay,		// $BthDay
				$Mob,			// $Mob,
				$Email,			// $Email
				$Gender,		// $Gender,
				$Contry,		// $Contry,
				$City,			// $City,
				$Paspot,		// $Paspot,
				$IdNo,			// $IdNo,
				$Memo,			// $Memo,
				$LogPw,			// $LogPw
				0				// 0為新增會員
		);


		try{

			$Mob = '0'.substr($Mob, -9);

			$curl = curl_init();

			curl_setopt_array($curl, array(
			CURLOPT_URL => $this->aUrl['newa'] .'?firstname='. $FName .'&lastname='. $LName .'&sex='. $Gender .'&birthday='. $BthDay .'&idno='. $IdNo .'&memo='. $Memo .'&email='. $Email .'&pw='. $LogPw .'&mbid=0&phone='. $Mob,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_HTTPHEADER => array(
				'Act: new',
				'Content-Type: application/json',
				'Authorization: Bearer '.$this->aUrl['token']
			),
			));
			$response = curl_exec($curl);
			
			curl_close($curl);
			$res = json_decode($response);
			$rtn['ErrNo'] = $res->rtn_code == '03'?'0':$res->rtn_code;
			$rtn['EffNum'] = $res->rtn_result->mbid;
		}catch (Exception $e){
			$rtn['ErrNo'] = '99';
			$rtn['EffNum'] = '連線失效';
		}

		// dd(json_decode(json_encode($rtn)));
		return $rtn;
	}

	//認證郵件
	public function doVerify($mid,$email){
		return null;
	}
	//搜尋身份証號
	public function findIdNo($cid,$hid,$email,$idNo){
		if(\Session::get('aHtlInfo.Cid') == $cid && \Session::get('aHtlInfo.id') == $hid){
			return null;
		}else{
			throw new \Exception('Session Fail!',330);
		}
	}
	//修改會員密碼?但麗緻會員不支援此功能
	public function saveLogPw($cid,$hid,$mid,$pw){
		if(\Session::get('aHtlInfo.Cid') == $cid && \Session::get('aHtlInfo.id') == $hid){
			return null;
		}else{
			throw new \Exception('Session Fail!',330);
		}		
	}
	// curl
	public function _curl_http($route,$act,$data,$token)
	{
		$string = sprintf('CURLOPT_URL:%s?%s,act=%s',$route,http_build_query($data),$act,$token);
		// 存log
		\UserLog::BackenTbLog('StayX10', 'C' . $this->Cid . 'H' . $this->Hid, $this->Cid, $this->Hid, 0, 'POST'.$string, __METHOD__, __LINE__);
		$curl = curl_init();
		curl_setopt_array($curl, array(
			// $loginUrl = 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query($parameters);
	
			// CURLOPT_URL => 'https://vue3.stayx.vip/memb?firstname=firstname&lastname=lastname&sex=M&birthday=1983-05-01&idno=A123456789&memo=%E6%88%91%E6%83%B3%E4%BE%BF%E5%AE%9C%E9%BB%9E&email=yoyodo128%40gmail.com&pw=asdfasdf&mbid=9999&phone=0912123123',
			CURLOPT_URL => $route.'?'.http_build_query($data),
			// CURLOPT_URL => 'https://vue3.stayx.vip/memb?uid=U543d8714a9002fb91129541ae53ce89c',
			// CURLOPT_URL => $this->aUrl['newa'].'?firstname=$FName&lastname=$LName&sex=$Gender&birthday=$BthDay&idno=$IdNo&memo=$Memo&email=$Email&pw=$LogPw&mbid=10001&phone=$Mob',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_HTTPHEADER => array(
				'Act: ' . $act,
				'Content-Type: application/json',
				'Authorization: Bearer '.$token
			),
		));

		$response = curl_exec($curl);

		curl_close($curl);

		\UserLog::BackenTbLog('StayX10', 'C' . $this->Cid . 'H' . $this->Hid, $this->Cid, $this->Hid, 0, 'BACK'.$response, __METHOD__, __LINE__);
		return json_decode($response);
	}
	// 找出資料庫是否存在會員?
	public function _getMemb($res)
	{
		$aRes = json_decode(json_encode($res),true)['rtn_result'];
		$grade = $aRes['grade'];	// 會員級別
		// $aRes['mid'] = $aRes['mbid'];	// 為Htl_Memb.id, 以便後續判斷會員資格
		// $aRes['Email'] = $aRes['email'];
		// $aRes['LoginPw'] = $pw;

		// 找出BOKI 對應會員級別及折扣
		$aMemb = \DB::table('Typ_Memb')
			// ->leftjoin('Dsc_Info AS Di', 'Di.RegApi','=','Typ_Memb.id')
			->select('Typ_Memb.WebName','Typ_Memb.Code','Typ_Memb.DiscCal')
			->where('Typ_Memb.Cid',$this->Cid)
			->where('Typ_Memb.Hid',$this->Hid)
			->where('Typ_Memb.Active','Y')
			// ->where('Di.Active','Y')
			->where('Typ_Memb.Code',$grade)
			->first();

		// 不是boki會員，直接新增一筆Htl_Memb.id，並存入資料
		$rtn = $this->_doAddOrUpd(StayX10::TYPE,			// $Route,
				\Session::get('StayX.data')['uid'],					// $SoId,
				$aRes['lastname'],		// $LName
				$aRes['firstname'],		// $FName
				$aRes['birthday'],		// $BthDay
				$aRes['phone'],			// $Mob,
				$aRes['email'],			// $Email
				$aRes['sex'],			// $Gender,
				'TW',					// $Contry,
				null,					// $City,
				null,					// $Paspot,
				$aRes['idno'],			// $IdNo,
				null,					// $Memo,
				null,					// $LogPw
				$aRes['mbid']			// 有會員id -> 將會員資料返回前台；沒有會員id -> 將會員資料寫入新增一筆Htl_Memb.id後，再將其返回前台
		);

		$switchMemb = \Apv3Helpers::switchChlMember($this->Cid,$this->Hid);
		$szChlHtls = \Apv3Helpers::szChlHtls('aChlHtls');
		// 把會員登入資訊存在Session，重整頁面後無需再次登入
		$key = (isset($switchMemb->Cid)?$switchMemb->Cid:$szChlHtls['Cid']).'_'.(isset($switchMemb->Hid)?$switchMemb->Hid:$szChlHtls['Hid']).'_'.(isset($switchMemb->Chn)?$switchMemb->Chn:$szChlHtls['ChlId']).'_'.(isset($switchMemb->Key)?$switchMemb->Key:$szChlHtls['LinKey']);
		$aC = array(
			'Type' 		=> 'B',
			'from'		=> 'StayX',	// StayX會員
			'Route' 	=> 'StayX',
			'id'		=> $aRes['mbid'],	// BOKI 會員id
			'aC' => array(
				'c_ssotyp'	=> 'StayX',	// StayX會員，存在Ord_Cust.SsoTyp
				'c_ssouid'	=> $aRes['id'],	// 記錄StayX會員號，存在Ord_Cust.SsoUid
				'c_fname'	=> $aRes['firstname'],
				'c_lname' 	=> $aRes['lastname'],
				'c_gender' 	=> $aRes['sex'],
				'c_bd' 		=> !empty($aRes['sex'])?$aRes['sex']:'',
				'c_phone' 	=> $aRes['phone'],
				'c_idnum' 	=> !empty($aRes['idno'])?$aRes['idno']:'',
				'c_passnum' => '',
				'c_email' 	=> $aRes['email'],
				'c_orderpw'	=> '',
				'memo' 		=> (!empty($aRes['memo'])?$aRes['memo'].',':'').(intval($aRes['rate']) != 0?'NO.'.$aRes['id'].',Discount:'.$aRes['rate'].'%':''),//住房備註
				'c_edm' 	=> false,//會員電子報 
				'c_coupon' 	=> false,//好康優惠券
				'c_country' => 'TW',//國籍
				'LineId' 	=> false,	//Stayx會員LineId一律回傳true，但不回傳ID號碼
				'LRId' 		=> null,	//Stayx會員LRId一律回傳false，但不回傳ID號碼
				'LRUrl' 	=> null,	//LRUrl
		));
		unset($aRes['id']);	// 移除原陣列裡的id，以$aC['id']為主
		if(!is_null($aMemb)){
			// 已是boki會員，把符合折扣條件存在Session裡
			$aMemb = json_decode(json_encode($aMemb),true);
			$aC = array_merge($aC,$aRes,$aMemb);
			\Cookie::queue($key, $aC, 10); // 10分鐘
			return $aC;
		}else{
			// 還不是boki會員，需新增
			\Redirect::to(CNF_APPURL.'/zhtw.v3.'.$this->Cid.'.'.$this->Hid.'.'.$this->Chlid.'.'.$this->Key.'/page?sg=60')->send();
			
		}


	}
	// 查詢StayX 折扣券狀態
	public function getInfo($code, $mid)
	{
		$data = array(
			'code'	=>	'CS998999247',
			// 'code'	=>	$code,
			'mbid'	=>	'9991'
			// 'mbid'	=>	$mid
		);
		\Session::set('StayX.data', $data);
		\Cookie::queue('StayX', $data, 1); // 10分鐘
			// 用uid 至 StayX 會員確認
			$result = $this->_curl_http($this->aUrl['cart'],'qry',$data,$this->aUrl['token']);	
		if($result->rtn_code == '00'){
			// 回覆成功正規劃格式
			// $oData['res']['WebName'] = json_encode();
			// $oData['res']['WebDesc'] = json_encode();
			$oData['res']['DiscCal'] = '';
			$oData['res']['ApndMemb'] = '';
		}else{
			throw new \Exception($result->rtn_message, $result->rtn_code);	// 退房日超過活動結束日
			
		}
			// $oData['res']
			return $result;
			dd($result);

			if(isset($result) && $result->rtn_code == '00'){
				// 已是會員 得到會員資料
				$isMemb = $this->_getMemb($result);
				return $isMemb;
			}
	}

	// 使用第三方line 登入驗証
	public function doLinelogin($func){
		$CHANNEL_ID = $this->CHANNEL_ID;
		$CHANNEL_SECRET = $this->CHANNEL_SECRET;
		$Csn = $this->Cid;
		$Hsn = $this->Hid;
		$Chn = $this->Chlid;
		$Key = $this->Key;

		$callback = CNF_APPURL.'/zhtw.v3.'.$Csn.'.'.$Hsn.'.'.$Chn.'/'.$Key.'/mb/linelogin';
		// dd($callback);
		//$callback = 'https://app.hihotel.asia/zhtw.v3.14.79.78/0D6D7D/Line/Login';
		// $callback = 'https://ona.hihotel.asia/'.$Lang.'.v3.'.$Csn.'.'.$Hsn.'.'.$Chn.'/'.$Key.'/Line/Login';
		// dd($CHANNEL_ID,$CHANNEL_SECRET);
		$csrf = csrf_token();
		//$url = 'https://access.line.me/oauth2/v2.1/authorize?response_type=code&client_id=' . $CHANNEL_ID . '&redirect_uri=' . ($callback) . '&state=' . $csrf;
		$config =  array(
			"base_url" => $callback . '/auth',
			"providers" => array(
				"LineLogin" => array(
					"enabled" => true,
					"callback" => $callback,
					"keys" => array("id" => $CHANNEL_ID, "secret" => $CHANNEL_SECRET),
				)
			)
		);
		
		\Session::set('config', $config);
		// dd($func, $config);
		$parameters = [
			'response_type' => 'code',
			'client_id' => $config['providers']['LineLogin']['keys']['id'],
			'redirect_uri' => $config['providers']['LineLogin']['callback'],
			'state' => $csrf,
			'scope' => 'profile',
			'nonce' => rand(100000, 999999)
		];

				// dd('Membcontroller doLinelogin before',\Session::all());

		// dd($parameters);

		$loginUrl = 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query($parameters);
		$code = \Input::get('code');
		if (is_null($code)) {
			// 如果還沒有授權碼，則重導向到 LINE 登入頁面
			\Redirect::to($loginUrl)->send();
		}
				// dd('Membcontroller doLinelogin back',\Session::all());

		if (!is_null($code)) {
			$responseData = $this->getAccessToken($code, $config);
			$tokens = $responseData['access_token'];
			$userProfile = $this->getUserProfile($tokens);

			if (isset($userProfile['userId'])) {
				$data = array(
					// 'uid'	=>	'U3ac971e7b476df8984ec78b0e08766c3'	// 可瑞測試uid
					// 'uid'	=>	'U3ac971e7b476df8984ec78b0e08766c2',	// 假的uid
					'uid'	=>	$userProfile['userId'],
					'nam'	=>	$userProfile['displayName'],
					'pic'	=>	$userProfile['pictureUrl']
				);
				\Session::set('StayX.data', $data);
				\Cookie::queue('StayX', $data, 1); // 10分鐘
				// try{
					// 用uid 至 StayX 會員確認
					$result = $this->_curl_http($this->aUrl['logi'],'chk',$data,$this->aUrl['token']);	

					if(isset($result) && $result->rtn_code == '00'){
						// 已是會員 得到會員資料
						$isMemb = $this->_getMemb($result);
						return $isMemb;
					}
					else{
						/*
							dd(\Session::get('StayX.data'));
							array(3) {
							["uid"]=>
							string(33) "U3ac971e7b476df8984ec78b0e08766c2"
							["nam"]=>
							string(19) "懶蘭兒(YoYo😜)"
							["pic"]=>
							string(134) "https://profile.line-scdn.net/0hAQ2KTb2NHn4fMgxRgUZgAW9iHRQ8Q0dsNgMFSHk3FBl1VVt7Y1EGTHo6FB0iAV19NwYFT38wFB4TIWkYAWTiShgCQ08jBl0sMFdZkA"
							}
						*/
						// 非會員 跳轉到加入會員頁
						// 前台畫面轉 1、舊會員登入 2、創建新會員
						// https://app.hihotel.asia/zhtw.v3.4.11.4.PLCRST/page?sg=60
						\Redirect::to(CNF_APPURL.'/zhtw.v3.'.$Csn.'.'.$Hsn.'.'.$Chn.'.'.$Key.'/page?sg=60')->send();
					}

					$UID = $userProfile['userId'];
					$name = isset($userProfile['displayName']) ? $userProfile['displayName'] : '';
					$photo = isset($userProfile['pictureUrl']) ? $userProfile['pictureUrl'] : '';
					$email = isset($userProfile['email']) ? $userProfile['email'] : '';
				// }catch(\Exception $e){

					// dd('1111');
					// 非會員 跳轉到加入會員頁
					// 前台畫面轉 => 2、創建新會員
					// https://app.hihotel.asia/zhtw.v3.4.11.4.PLCRST/page?sg=60
				// 	\Redirect::to(CNF_APPURL.'/zhtw.v3.'.$Csn.'.'.$Hsn.'.'.$Chn.'.'.$Key.'/page?sg=60')->send();
				// }
			} else {
				\Redirect::to($loginUrl)->send();
				exit;
			}
		} else {
			// 重新導向至 LINE Login 要求用戶授權的頁面
			\Redirect::to($loginUrl)->send();
			exit;
		}
	}
	/*
	   ### https://v3m.hihotel.asia/user/socilogin/LineLogin
	   ### https://v3m.hihotel.asia/user/socilogin/Facebook
	   ### https://v3m.hihotel.asia/user/socilogin/Google
	   提供所有第三方認証Login 使用，
	   若UniKey已存在，則進行管理者資格審查，登入成功後發信給該管理者，若該管理者非上架，則發信給旅盟
	   若UniKey不存在，則引導管理者登入飯店帳號進行綁定並發信通知該管理者
	   UniKey為$providerId+UID

	   為$providerId = 'AOL','Facebook','Foursquare','Google','LineLogin','LineNotify','LinkedIn','Live','MySpace','OpenID','Twitter','Yahoo';
	   */

	function getAccessToken($code, $config)
	{
		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_URL => 'https://api.line.me/oauth2/v2.1/token',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query([
				'grant_type' => 'authorization_code',
				'code' => $code,
				'redirect_uri' => $config['providers']['LineLogin']['callback'],
				'client_id' => $config['providers']['LineLogin']['keys']['id'],
				'client_secret' => $config['providers']['LineLogin']['keys']['secret'],
			]),
			CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
		]);

		$response = curl_exec($curl);
		if (curl_errno($curl)) {
			throw new Exception(curl_error($curl));
		}
		curl_close($curl);

		return json_decode($response, true);
	}

	function getUserProfile($accessToken)
	{
		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_URL => 'https://api.line.me/v2/profile',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}"],
		]);

		$response = curl_exec($curl);
		if (curl_errno($curl)) {
			throw new Exception(curl_error($curl));
		}
		curl_close($curl);

		return json_decode($response, true);
	}
}