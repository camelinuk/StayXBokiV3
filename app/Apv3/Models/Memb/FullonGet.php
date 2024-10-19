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

use Apv3\Models\InfMemb as InfMemb;

class FullonGet implements InfMemb
{
	protected $aLogin,$Cid,$Hid;
	const TYPE = 'FullonGet';
	
	protected $aUrl = array(
		'logi' => 'https://app10.e-giant.com.tw/pms_api/getMemberLevel.asp?phone=%s&pw=%s',
		// 'logi' => 'https://membership.fullon-hotels.com.tw/pms_api/getMemberLevel.asp?phone=%s&pw=%s',
		'forg' => null,
		'newa' => null,
		'edit' => null,
		'delt' => null,
	);
	public function __construct($cid,$hid) {
		$this->Cid = (int)$cid;
		$this->Hid = (int)$hid;
	}
	/*

	若有查到會員，會傳回
	rtn_code代碼		說明
	00					查詢成功(回傳會員資料)
	01					查詢失敗，請提供必填資料
	02					查詢失敗，查無此會員資料
	[
	  {
		"rtn_message": "查詢成功",
		"rtn_code": "00",
		"timestamp": "20230814T143527",
		"rtn_result": [
		  {
			"id": "A0811616",
			"grade": "FULMBNEW",
			"rate": "95",
			"firstname": "",
			"lastname": "陳玉羚",
			"phone": "0912887888"
		  }
		]
	  }
	]
	*/
	//會員登入
	public function doLogin($route,$email,$pw){
		$aT = $aC =  $aG =  $aCo = [];
		$url = sprintf($this->aUrl['logi'],$email,$pw);
		// var_dump($this->aUrl['logi'],$email,$pw);
		$arrContextOptions=array(
			"ssl"=>array(
				"verify_peer"=>false,
				"verify_peer_name"=>false,
			),
		);  
		// 存log
		\UserLog::BackenTbLog('FullonMember', 'C' . $this->Cid . 'H' . $this->Hid, $this->Cid, $this->Hid, 0, 'POST'.$url, __METHOD__, __LINE__);
		
		$cnt = file_get_contents($url, false, stream_context_create($arrContextOptions));
		\UserLog::BackenTbLog('FullonMember', 'C' . $this->Cid . 'H' . $this->Hid, $this->Cid, $this->Hid, 0, 'BACK'.$cnt, __METHOD__, __LINE__);
		// dd($cnt,$url);
		//$cnt = '"Y","09211240004","null","null","null","null/null/null","null","0","0","0"';
		//$cnt = '"Y","09211240004","鄭怡宜","F","F203358501","1976/05/29","0920805865","4200","0","0"';
		//$cnt = '"Y","0900000123","群豐測試","M","","1976/01/01","0900000123","0","1000","0"';
		$aCnt = json_decode($cnt);

		if($aCnt[0]->rtn_code == '02')	// 查詢失敗，查無此會員資料
			throw new \Exception($aCnt[0]->rtn_message, 202);
		else if ($aCnt[0]->rtn_code == '01')	// 查詢失敗，請提供必填資料
			throw new \Exception($aCnt[0]->rtn_message, 204);
		else if($aCnt[0]->rtn_code == '00'){
			$aRes = json_decode(json_encode($aCnt[0]->rtn_result[0]),true);
			// dd($aRes);
			//處理姓、名分開的程式
			if(empty($aRes['firstname'])){
				$name = $aRes['lastname'];
				if(count($name)==1){
					if(mb_strlen($name)<=3){
						$ln = mb_substr($name,0,1);
						$fn = mb_substr($name,1);
					}else{
						$ln = mb_substr($name,0,2);
						$fn = mb_substr($name,2);
					}
				}elseif(count($name)>=2){
					$ln = $name;
					$fn = $name;
				}
				
			}else{
				$ln = $aRes['lastname'];
				$fn = $aRes['firstname'];
			
			}
			$grade = $aRes['grade'];
			$aRes['mid'] = $aRes['id'];	// 假設為Htl_Memb.id, 以便後續判斷會員資格
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
			$switchMemb = \Apv3Helpers::switchChlMember($this->Cid,$this->Hid);
			$szChlHtls = \Apv3Helpers::szChlHtls('aChlHtls');
			// 把會員登入資訊存在Session，重整頁面後無需再次登入
			$key = (isset($switchMemb->Cid)?$switchMemb->Cid:$szChlHtls['Cid']).'_'.(isset($switchMemb->Hid)?$switchMemb->Hid:$szChlHtls['Hid']).'_'.(isset($switchMemb->Chn)?$switchMemb->Chn:$szChlHtls['ChlId']).'_'.(isset($switchMemb->Key)?$switchMemb->Key:$szChlHtls['LinKey']);
			// \Session::put('aHtlMemb.'.$key, array_merge($aRes,$aMemb->toArray()));
			$aC = array(
				'Type' 		=> 'B',
				'from'		=> 'FULLON',	// 福容集團會員
				'Route' 	=> 'FULLON',
				'aC' => array(
					'c_ssotyp'	=> 'FULLON',	// 福容集團會員，存在Ord_Cust.SsoTyp
					'c_ssouid'	=> $aRes['id'],	// 錄福容會員號，存在Ord_Cust.SsoUid
					'c_fname'	=> $fn,
					'c_lname' 	=> $ln,
					'c_gender' 	=> 'U',
					'c_bd' 		=> '',
					'c_phone' 	=> $aRes['phone'],
					'c_idnum' 	=> '',
					'c_passnum' 	=> '',
					'c_email' 	=> '',
					'c_orderpw'	=> $pw,
					'memo' 		=> 'NO.'.$aRes['id'].',Discount:'.$aRes['rate'].'%',//住房備註
					'c_edm' 		=> false,//會員電子報 
					'c_coupon' 	=> false,//好康優惠券
					'c_country' 	=> 'TW',//國籍
			));
			if(!is_null($aMemb)){
				// 把符合折扣條件存在Session裡
				$aMemb = json_decode(json_encode($aMemb),true);
				$aC = array_merge($aC,$aRes,$aMemb);
				\Session::put('aHtlMemb.'.$key, array_merge($aRes,$aMemb,$aC));
			}
			// dd($aC,\Session::get('aHtlMemb.'.$key));
		}else{
			throw new \Exception('member login fail',210);
		}

		//dd($aT,$aG,$aCo);
		$this->aLogin = array_merge($aC,$aT,$aG,$aCo);
		return $this->aLogin;
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
}