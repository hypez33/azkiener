<?php
declare(strict_types=1);
ob_start();
ini_set('display_errors','0');
error_reporting(E_ALL);

function finish_json($payload, int $status=200){
  while(ob_get_level()>0){ob_end_clean();}
  header('Content-Type: application/json; charset=utf-8');
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function cache_dir(): string{
  $d=sys_get_temp_dir().DIRECTORY_SEPARATOR.'azkiener_cache';
  if(!file_exists($d)) @mkdir($d,0777,true);
  return $d;
}
function cache_get(string $key,int $ttl){
  $p=cache_dir().DIRECTORY_SEPARATOR.md5($key).'.json';
  if(!file_exists($p)) return null;
  if((time()-filemtime($p))>$ttl) return null;
  $t=@file_get_contents($p);
  return $t===false?null:json_decode($t,true);
}
function cache_set(string $key,$value):void{
  $p=cache_dir().DIRECTORY_SEPARATOR.md5($key).'.json';
  @file_put_contents($p,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);
}
function http_basic_get_json(string $url,string $username,string $password){
  $ch=curl_init();
  curl_setopt_array($ch,[
    CURLOPT_URL=>$url,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>25,
    CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
    CURLOPT_USERPWD=>$username.':'.$password,
    CURLOPT_HTTPHEADER=>[
      'Accept: application/vnd.de.mobile.api+json',
      'Accept-Encoding: gzip',
      'User-Agent: Azkiener/1.0 (+vercel)'
    ],
  ]);
  $resp=curl_exec($ch);
  $err=curl_error($ch);
  $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
  curl_close($ch);
  if($err||$code>=400||$resp===false||$resp==='') return [null,$code];
  $json=json_decode($resp,true);
  return $json===null?[null,$code]:[$json,null];
}
function fmt_price_label($amount):string{
  if($amount===null) return '0 €';
  $n=number_format((float)$amount,0,',','.');
  return $n.' €';
}
function gear_label($value){
  $v=strtolower((string)$value);
  if(strpos($v,'auto')!==false||strpos($v,'dsg')!==false) return 'Automatic_gear';
  return 'Manual_gear';
}
function first_year_from($val):int{
  if(is_string($val)&&preg_match('/^([0-9]{4})/',$val,$m)) return (int)$m[1];
  return 0;
}
function pick_image_url_from_ad(array $ad):?string{
  if(isset($ad['images']['image'])&&is_array($ad['images']['image'])){
    foreach($ad['images']['image'] as $img){
      if(isset($img['representation'])&&is_array($img['representation'])){
        $pref=['XL','XXL','L','M','S','ICON'];
        $bySize=[];
        foreach($img['representation'] as $rep){
          $size=strtoupper($rep['size']??'');
          $url=$rep['url']??null;
          if($size&&$url) $bySize[$size]=$url;
        }
        foreach($pref as $p) if(!empty($bySize[$p])) return $bySize[$p];
      }
      foreach(['xl','xxl','l','m','s','icon'] as $k){
        if(!empty($img[$k])) return $img[$k];
      }
      if(!empty($img['url'])) return $img['url'];
    }
  }
  if(isset($ad['images'])&&is_array($ad['images'])){
    foreach($ad['images'] as $img){
      foreach(['xl','xxl','l','m','s','icon','url'] as $k){
        if(!empty($img[$k])) return $img[$k];
      }
    }
  }
  return null;
}

$user=getenv('MOBILE_USER')?:'';
$pass=getenv('MOBILE_PASSWORD')?:'';
$cust=getenv('CUSTOMER_NUMBERS')?:'';
if($user===''||$pass===''||$cust===''){
  finish_json(["ts"=>time(),"data"=>[]],200);
}

$ttl=isset($_GET['ttl'])?max(30,(int)$_GET['ttl']):300;
$force=isset($_GET['force']);
$limit=isset($_GET['limit'])?max(1,min(200,(int)$_GET['limit'])):60;

$base='https://services.mobile.de/search-api';
$query='customerNumber='.rawurlencode($cust).'&country=DE&sort.field=modificationTime&sort.order=DESCENDING&page.size=100';
$cacheKey='compat_schema_'.md5($query.'|limit='.$limit);

if(!$force){
  $cached=cache_get($cacheKey,$ttl);
  if($cached!==null) finish_json($cached,200);
}

$items=[];
$page=1;$maxPages=30;
while($page<=$maxPages&&count($items)<$limit){
  [$j,$err]=http_basic_get_json($base.'/search?'.$query.'&page.number='.$page,$user,$pass);
  if($err) break;
  $sr=$j['searchResult']??null;
  $ads=is_array($sr)?($sr['ads']??[]):($j['ads']??[]);
  if(!is_array($ads)||!count($ads)) break;
  foreach($ads as $ad){
    $adId=(string)($ad['mobileAdId']??$ad['id']??$ad['key']??'');
    $url=$ad['detailPageUrl']??'';
    $title=$ad['title']??trim(($ad['make']??'').' '.($ad['model']??''));
    $priceAmount=null;
    if(isset($ad['price']['consumerPriceGross'])) $priceAmount=(float)$ad['price']['consumerPriceGross'];
    elseif(isset($ad['price']['consumerPrice']['amount'])) $priceAmount=(float)$ad['price']['consumerPrice']['amount'];
    $fuel=$ad['fuel']??"";
    $km=(int)($ad['mileageInKm']??$ad['mileage']??0);
    $year=first_year_from($ad['firstRegistration']??($ad['firstRegistrationDate']??''));
    $imgUrl=pick_image_url_from_ad($ad);
    $imgProxy=$imgUrl?('/img.php?src='.rawurlencode($imgUrl)):"";
    $items[]=[
      "adId"=>$adId,"url"=>$url,"title"=>$title,
      "price"=>(int)($priceAmount??0),"priceLabel"=>fmt_price_label($priceAmount),
      "specs"=>number_format($km,0,',','.').' km · '.$fuel.' · '.gear_label($ad['gearbox']??($ad['transmission']??'')),
      "fuel"=>$fuel,"km"=>$km,"year"=>$year,"img"=>$imgProxy
    ];
    if(count($items)>=$limit) break;
  }
  $current=(int)($sr['currentPage']??$page);
  $max=(int)($sr['maxPages']??$page);
  $maxPages=max(1,min($maxPages,$max));
  $page=$current+1;
}
$result=["ts"=>time(),"data"=>$items];
cache_set($cacheKey,$result);
finish_json($result,200);
