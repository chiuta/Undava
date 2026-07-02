<?php
/* ============================================================================
   Undava — server edition (flat-file storage, GuppY-style)
   One self-contained PHP file: serves the game UI AND a small JSON API.
   No SQL database. All content lives as plain text under ./data :
     - data/quizzes/<id>.json   one quiz per file (human-readable JSON)
     - data/feedback.txt        visitor feedback, one record per line
     - data/.config.php         admin password hash + settings (kept private)
   Requires PHP 7.4+ and a writable ./data directory.
   ========================================================================== */

session_start();

$BASE = __DIR__;
$DATA = $BASE . '/data';
$QDIR = $DATA . '/quizzes';
$FB   = $DATA . '/feedback.txt';
$CFG  = $DATA . '/.config.php';

define('MAX_TITLE',200); define('MAX_DESC',1000); define('MAX_Q',100); define('MAX_A',6);
define('MAX_QTEXT',500); define('MAX_ATEXT',300); define('MAX_NAME',60); define('MAX_MSG',2000);
define('MAX_BODY',600000); define('FB_COOLDOWN',8);

function qff_boot(){
  global $DATA,$QDIR,$FB;
  if(!is_dir($DATA)) @mkdir($DATA,0775,true);
  if(!is_dir($QDIR)) @mkdir($QDIR,0775,true);
  if(!is_dir($DATA.'/live')) @mkdir($DATA.'/live',0775,true);
  $ht=$DATA.'/.htaccess';
  if(!file_exists($ht)) @file_put_contents($ht,"Require all denied\nDeny from all\nOptions -Indexes\n");
  $ix=$DATA.'/index.html';
  if(!file_exists($ix)) @file_put_contents($ix,"<!doctype html><title>403</title>");
  if(!file_exists($FB)) @file_put_contents($FB,"# Quiz fara frontiere feedback (pipe-delimited). fields: ts|status|quizId|quizTitle|name|rating|message\n");
}
qff_boot();

/* =========================== PWA ASSETS =========================== */
function qff_icon_svg($mask){
  $rx = $mask ? 0 : 112; $s = $mask ? 0.74 : 0.92;
  $cx=256; $cy=256; $g=132*$s; $r=92*$s;
  $tlx=$cx-$g; $tly=$cy-$g; $trx=$cx+$g; $try=$cy-$g; $blx=$cx-$g; $bly=$cy+$g; $brx=$cx+$g; $bry=$cy+$g;
  $tri=sprintf('<polygon points="%.1f,%.1f %.1f,%.1f %.1f,%.1f" fill="#ff4e8a"/>',$tlx,$tly-$r,$tlx-$r*0.92,$tly+$r*0.72,$tlx+$r*0.92,$tly+$r*0.72);
  $dia=sprintf('<polygon points="%.1f,%.1f %.1f,%.1f %.1f,%.1f %.1f,%.1f" fill="#2f6bff"/>',$trx,$try-$r,$trx+$r,$try,$trx,$try+$r,$trx-$r,$try);
  $cir=sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="#ffd23f"/>',$blx,$bly,$r);
  $sq=sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="%.1f" fill="#2ee6c4"/>',$brx-$r,$bry-$r,$r*2,$r*2,$r*0.28);
  return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512"><rect width="512" height="512" rx="'.$rx.'" fill="#16092e"/>'.$tri.$dia.$cir.$sq.'</svg>';
}
function qff_sw_js(){ return <<<'SWJS'
const C='qff-shell-v2';
const SHELL=['./','?asset=manifest','?asset=icon'];
self.addEventListener('install',e=>{ e.waitUntil(caches.open(C).then(c=>c.addAll(SHELL).catch(()=>{})).then(()=>self.skipWaiting())); });
self.addEventListener('activate',e=>{ e.waitUntil(caches.keys().then(ks=>Promise.all(ks.map(k=>k===C?null:caches.delete(k)))).then(()=>self.clients.claim())); });
self.addEventListener('fetch',e=>{
  const req=e.request; if(req.method!=='GET') return;
  const url=new URL(req.url); if(url.origin!==location.origin) return;
  if(url.searchParams.has('api')) return;
  const shell = req.mode==='navigate' || url.searchParams.has('asset') || url.pathname.endsWith('/') || url.pathname.endsWith('index.php');
  if(shell){ e.respondWith(fetch(req).then(r=>{ const cp=r.clone(); caches.open(C).then(c=>c.put(req,cp)).catch(()=>{}); return r; }).catch(()=>caches.match(req).then(m=>m||caches.match('./')))); return; }
  e.respondWith(caches.match(req).then(m=>m||fetch(req)));
});
SWJS;
}
if(isset($_GET['asset'])){
  $a=$_GET['asset'];
  if($a==='manifest'){
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode(array(
      'name'=>'Undava','short_name'=>'Quiz',
      'description'=>'Joc de quiz în spiritul Kahoot — single-file, offline-first, fără conturi, fără reclame, fără telemetrie.',
      'lang'=>'ro','dir'=>'ltr','start_url'=>'./','scope'=>'./','display'=>'standalone','orientation'=>'any',
      'background_color'=>'#16092e','theme_color'=>'#16092e','categories'=>array('education','games'),
      'icons'=>array(
        array('src'=>'?asset=icon','sizes'=>'any','type'=>'image/svg+xml','purpose'=>'any'),
        array('src'=>'?asset=icon&m=1','sizes'=>'any','type'=>'image/svg+xml','purpose'=>'maskable')
      )
    ), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }
  if($a==='icon'){ header('Content-Type: image/svg+xml; charset=utf-8'); header('Cache-Control: public, max-age=604800'); echo qff_icon_svg(!empty($_GET['m'])); exit; }
  if($a==='sw'){ header('Content-Type: application/javascript; charset=utf-8'); echo qff_sw_js(); exit; }
  http_response_code(404); echo 'not found'; exit;
}

function qff_atomic($path,$data){
  $tmp=$path.'.tmp'.bin2hex(random_bytes(4));
  if(@file_put_contents($tmp,$data,LOCK_EX)===false) return false;
  return @rename($tmp,$path);
}
function qff_cfg(){
  global $CFG;
  if(file_exists($CFG)){ $c=include $CFG; if(is_array($c)) return $c; }
  return array('admin_hash'=>'','moderate'=>false);
}
function qff_cfg_save($c){
  global $CFG;
  qff_atomic($CFG,"<?php\n// Quiz fara frontiere config. Keep this file private.\nreturn ".var_export($c,true).";\n");
}
function qff_json($x,$code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  echo json_encode($x,JSON_UNESCAPED_UNICODE);
  exit;
}
function qff_err($m,$code=400){ qff_json(array('error'=>$m),$code); }
function qff_body(){
  $raw=file_get_contents('php://input');
  if($raw===false||$raw==='') return array();
  if(strlen($raw)>MAX_BODY) qff_err('payload too large',413);
  $d=json_decode($raw,true);
  return is_array($d)?$d:array();
}
function qff_is_admin(){ return !empty($_SESSION['qff_admin']); }
function qff_csrf(){ if(empty($_SESSION['qff_csrf'])) $_SESSION['qff_csrf']=bin2hex(random_bytes(16)); return $_SESSION['qff_csrf']; }
function qff_check_csrf($b){ $tk=isset($b['csrf'])?$b['csrf']:''; if(!is_string($tk)||!hash_equals(qff_csrf(),$tk)) qff_err('bad csrf',403); }
function qff_require_admin(){ if(!qff_is_admin()) qff_err('admin required',401); }
function qff_safe_id($id){ $id=preg_replace('/[^A-Za-z0-9_-]/','',(string)$id); return substr($id,0,64); }
function qff_gen_id(){ return 'q_'.base_convert((string)time(),10,36).'_'.bin2hex(random_bytes(3)); }
function qff_clip($s,$n){ $s=is_string($s)?$s:''; $s=trim($s);
  if(function_exists('mb_substr')){ if(mb_strlen($s)>$n) $s=mb_substr($s,0,$n); } else { if(strlen($s)>$n) $s=substr($s,0,$n); }
  return $s; }

function qff_norm_text($s){ $s=(string)$s;
  if(class_exists('Normalizer')){ $n=Normalizer::normalize($s, Normalizer::FORM_D); if($n!==false&&$n!==null){ $s=preg_replace('/\\p{Mn}+/u','',$n); } }
  else { $s=strtr($s, array('ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t','Ă'=>'a','Â'=>'a','Î'=>'i','Ș'=>'s','Ț'=>'t','é'=>'e','è'=>'e','ê'=>'e','á'=>'a','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u','ö'=>'o','ä'=>'a')); }
  $s=function_exists('mb_strtolower')?mb_strtolower($s,'UTF-8'):strtolower($s);
  $s=preg_replace('/[^a-z0-9\\s]/u',' ',$s); $s=preg_replace('/\\s+/',' ',$s); return trim($s); }
function qff_num_parse($s){ $s=trim(str_replace(',','.',(string)$s)); if($s===''||!is_numeric($s)) return null; return (float)$s; }
function qff_num_score($guess,$target,$tol){ $g=qff_num_parse($guess); $t=qff_num_parse($target); if($g===null||$t===null) return array('ok'=>false,'close'=>0.0); $d=abs($g-$t); $tol=abs((float)$tol); $ok=($d<=$tol); $close=($tol>0)?max(0.0,1.0-0.5*($d/$tol)):1.0; return array('ok'=>$ok,'close'=>($ok?$close:0.0)); }
function qff_text_match($input,$accepted){ $g=qff_norm_text($input); if($g==='') return false;
  foreach((array)$accepted as $acc){ $a=qff_norm_text($acc); if($a==='') continue; if($g===$a) return true;
    $allowed=min(2, intdiv(strlen($a),4)); if($allowed>0 && levenshtein($g,$a)<=$allowed) return true; }
  return false; }
function qff_norm_quiz($q){
  if(!is_array($q)) qff_err('invalid quiz');
  $title=qff_clip(isset($q['title'])?$q['title']:'',MAX_TITLE); if($title==='') qff_err('missing title');
  $desc=qff_clip(isset($q['desc'])?$q['desc']:'',MAX_DESC);
  $color=(isset($q['color'])&&is_string($q['color'])&&preg_match('/^#[0-9a-fA-F]{3,8}$/',$q['color']))?$q['color']:'#2f6bff';
  $qs=isset($q['questions'])?$q['questions']:array();
  if(!is_array($qs)||count($qs)<1) qff_err('no questions');
  if(count($qs)>MAX_Q) qff_err('too many questions');
  $out=array();
  foreach($qs as $qq){
    if(!is_array($qq)) continue;
    $rawtype=isset($qq['type'])?$qq['type']:'quiz'; $type=in_array($rawtype,array('tf','type','num'),true)?$rawtype:'quiz';
    $text=qff_clip(isset($qq['text'])?$qq['text']:'',MAX_QTEXT); if($text==='') qff_err('empty question');
    $time=(int)(isset($qq['time'])?$qq['time']:20); if($time<5)$time=5; if($time>120)$time=120;
    $points=(int)(isset($qq['points'])?$qq['points']:1000); $points=($points>=2000)?2000:1000;
    $ans=isset($qq['answers'])?$qq['answers']:array(); if(!is_array($ans)) $ans=array();
    $na=array();
    foreach($ans as $a){ if(!is_array($a)) continue; $na[]=array('text'=>qff_clip(isset($a['text'])?$a['text']:'',MAX_ATEXT),'correct'=>!empty($a['correct'])); }
    $na=array_slice($na,0,MAX_A);
    if($type==='type'){ $na=array_values(array_filter($na,function($a){ return $a['text']!==''; })); if(count($na)<1) qff_err('need an accepted answer'); foreach($na as $k=>$a){ $na[$k]['correct']=true; } }
    else if($type==='num'){ $na=array_values(array_filter($na,function($a){ return $a['text']!==''; })); if(count($na)<1 || qff_num_parse($na[0]['text'])===null) qff_err('need a number'); $na=array_slice($na,0,1); $na[0]['correct']=true; }
    else if($type!=='tf'){ $na=array_values(array_filter($na,function($a){ return $a['text']!==''; })); if(count($na)<2) qff_err('need 2 answers'); }
    $hasC=false; foreach($na as $a){ if($a['correct']){ $hasC=true; break; } }
    if(!$hasC) qff_err('need a correct answer');
    $tol=0.0; if($type==='num'){ $tp=qff_num_parse(isset($qq['tol'])?$qq['tol']:0); $tol=($tp===null)?0.0:abs($tp); }
    $out[]=array('text'=>$text,'time'=>$time,'points'=>$points,'type'=>$type,'answers'=>$na,'tol'=>$tol);
  }
  $id=qff_safe_id(isset($q['id'])?$q['id']:''); if($id==='') $id=qff_gen_id();
  return array('id'=>$id,'color'=>$color,'title'=>$title,'desc'=>$desc,'questions'=>$out);
}

/* feedback: one record per line, pipe-delimited; fields rawurl-encoded (lossless, no delimiter clashes) */
function qff_fb_enc($s){ return rawurlencode((string)$s); }
function qff_fb_dec($s){ return rawurldecode((string)$s); }
function qff_fb_line($e){
  return implode('|',array((int)$e['ts'], ($e['status']==='pend'?'pend':'pub'),
    qff_fb_enc($e['quizId']), qff_fb_enc($e['quizTitle']), qff_fb_enc($e['name']), (int)$e['rating'], qff_fb_enc($e['message'])));
}
function qff_fb_all(){
  global $FB; $rows=array();
  if(!file_exists($FB)) return $rows;
  $fh=fopen($FB,'r'); if(!$fh) return $rows;
  while(($line=fgets($fh))!==false){
    $line=rtrim($line,"\r\n"); if($line===''||$line[0]==='#') continue;
    $p=explode('|',$line); if(count($p)<7) continue;
    $rows[]=array('ts'=>(int)$p[0],'status'=>$p[1],'quizId'=>qff_fb_dec($p[2]),'quizTitle'=>qff_fb_dec($p[3]),
      'name'=>qff_fb_dec($p[4]),'rating'=>(int)$p[5],'message'=>qff_fb_dec($p[6]));
  }
  fclose($fh); return $rows;
}
function qff_fb_write_all($rows){
  global $FB;
  $buf="# Quiz fara frontiere feedback (pipe-delimited). fields: ts|status|quizId|quizTitle|name|rating|message\n";
  foreach($rows as $r){ $buf.=qff_fb_line($r)."\n"; }
  qff_atomic($FB,$buf);
}


/* ----- live sessions (flat-file, one JSON per code) ----- */
function qff_live_path($code){ global $DATA; return $DATA.'/live/'.$code.'.json'; }
function qff_safe_code($c){ $c=strtoupper((string)$c); $c=preg_replace('/[^A-Z0-9]/','',$c); return substr($c,0,12); }
function qff_gen_code(){ $al='ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; do{ $c=''; for($i=0;$i<4;$i++) $c.=$al[random_int(0,strlen($al)-1)]; } while(file_exists(qff_live_path($c))); return $c; }
function qff_live_load($code){ $f=qff_live_path($code); if(!file_exists($f)) return null; $j=json_decode((string)file_get_contents($f),true); return is_array($j)?$j:null; }
function qff_live_write($s){ global $DATA; if(!is_dir($DATA.'/live')) @mkdir($DATA.'/live',0775,true); return qff_atomic(qff_live_path($s['code']), json_encode($s, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); }
function qff_live_mutate($code,$fn){
  global $DATA; $dir=$DATA.'/live'; if(!is_dir($dir)) @mkdir($dir,0775,true);
  $f=qff_live_path($code); if(!file_exists($f)) return array(null,'missing');
  $fh=fopen($f,'r+'); if(!$fh) return array(null,'io'); if(!flock($fh,LOCK_EX)){ fclose($fh); return array(null,'io'); }
  $raw=stream_get_contents($fh); $s=json_decode($raw,true);
  if(!is_array($s)){ flock($fh,LOCK_UN); fclose($fh); return array(null,'corrupt'); }
  $res=$fn($s);
  if($res===false){ flock($fh,LOCK_UN); fclose($fh); return array($s,'reject'); }
  $s=$res; ftruncate($fh,0); rewind($fh); fwrite($fh, json_encode($s, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); fflush($fh); flock($fh,LOCK_UN); fclose($fh);
  return array($s,'ok');
}
function qff_live_public($s,$admin){
  $type=$s['type']; $entries=isset($s['entries'])?$s['entries']:array(); $count=0; $results=array(); $ex=array();
  if($type==='cloud'){
    $cnt=array(); $disp=array();
    foreach($entries as $e){ if(!empty($e['hidden'])) continue; $w=trim((string)$e['text']); if($w==='') continue; $count++;
      $k=function_exists('mb_strtolower')?mb_strtolower($w):strtolower($w); if(!isset($cnt[$k])){ $cnt[$k]=0; $disp[$k]=$w; } $cnt[$k]++; }
    foreach($cnt as $k=>$c) $results[]=array('text'=>$disp[$k],'count'=>$c);
    usort($results,function($a,$b){ if($b['count']!==$a['count']) return $b['count']-$a['count']; return strcmp($a['text'],$b['text']); });
    $results=array_slice($results,0,150);
  } else if($type==='poll'){
    $opts=isset($s['options'])?$s['options']:array(); $c=array_fill(0,count($opts),0);
    foreach($entries as $e){ if(!empty($e['hidden'])) continue; $i=array_search($e['text'],$opts,true); if($i!==false){ $c[$i]++; $count++; } }
    foreach($opts as $i=>$o) $results[]=array('label'=>$o,'count'=>$c[$i]);
  } else if($type==='rating'){
    $scale=(int)(isset($s['scale'])?$s['scale']:5); if($scale!==10) $scale=5;
    $dist=array_fill(0,$scale+1,0); $sum=0;
    foreach($entries as $e){ if(!empty($e['hidden'])) continue; $v=(int)(isset($e['value'])?$e['value']:-1); if($v<0||$v>$scale) continue; $dist[$v]++; $sum+=$v; $count++; }
    $start=($scale===5)?1:0; for($v=$start;$v<=$scale;$v++) $results[]=array('value'=>$v,'count'=>$dist[$v]);
    $ex['scale']=$scale; $ex['average']=$count?round($sum/$count,2):0;
    if($scale===10){ $prom=0;$det=0; for($v=0;$v<=$scale;$v++){ if($v>=9)$prom+=$dist[$v]; elseif($v<=6)$det+=$dist[$v]; } $ex['nps']=$count?(int)round(($prom-$det)/$count*100):0; $ex['promoters']=$prom; $ex['detractors']=$det; $ex['passives']=$count-$prom-$det; }
  } else if($type==='rank'){
    $opts=isset($s['options'])?$s['options']:array(); $n=count($opts); $posSum=array_fill(0,$n,0);
    foreach($entries as $e){ if(!empty($e['hidden'])) continue; $ord=isset($e['order'])?$e['order']:null; if(!is_array($ord)||count($ord)!==$n) continue;
      $seen=array(); $ok=true; foreach($ord as $idx){ $idx=(int)$idx; if($idx<0||$idx>=$n||isset($seen[$idx])){ $ok=false; break; } $seen[$idx]=1; } if(!$ok) continue;
      foreach($ord as $pos=>$idx){ $posSum[(int)$idx]+=($pos+1); } $count++; }
    for($i=0;$i<$n;$i++) $results[]=array('label'=>$opts[$i],'avg'=>($count?round($posSum[$i]/$count,2):0),'index'=>$i);
    if($count>0) usort($results,function($a,$b){ if($a['avg']==$b['avg']) return $a['index']-$b['index']; return ($a['avg']<$b['avg'])?-1:1; });
  } else if($type==='scale'){
    $stmts=isset($s['statements'])?$s['statements']:array(); $m=count($stmts); $sum=array_fill(0,$m,0); $cnt=array_fill(0,$m,0);
    foreach($entries as $e){ if(!empty($e['hidden'])) continue; $r=isset($e['ratings'])?$e['ratings']:null; if(!is_array($r)||count($r)!==$m) continue; $ok=true; foreach($r as $v){ $v=(int)$v; if($v<1||$v>5){ $ok=false; break; } } if(!$ok) continue; $count++; foreach($r as $i=>$v){ $sum[$i]+=(int)$v; $cnt[$i]++; } }
    for($i=0;$i<$m;$i++) $results[]=array('statement'=>$stmts[$i],'avg'=>($cnt[$i]?round($sum[$i]/$cnt[$i],2):0),'count'=>$cnt[$i]);
    $ex['scaleMax']=5;
  } else if($type==='points'){
    $opts=isset($s['options'])?$s['options']:array(); $n=count($opts); $budget=(int)(isset($s['budget'])?$s['budget']:100); $tot=array_fill(0,$n,0); $grand=0;
    foreach($entries as $e){ if(!empty($e['hidden'])) continue; $al=isset($e['alloc'])?$e['alloc']:null; if(!is_array($al)||count($al)!==$n) continue; $se=0; $ok=true; foreach($al as $p){ $p=(int)$p; if($p<0){ $ok=false; break; } $se+=$p; } if(!$ok||$se>$budget) continue; $count++; foreach($al as $i=>$p){ $tot[$i]+=(int)$p; $grand+=(int)$p; } }
    for($i=0;$i<$n;$i++) $results[]=array('label'=>$opts[$i],'total'=>$tot[$i],'avg'=>($count?round($tot[$i]/$count,1):0),'pct'=>($grand>0?(int)round($tot[$i]/$grand*100):0));
    usort($results,function($a,$b){ return $b['total']-$a['total']; });
    $ex['budget']=$budget; $ex['grand']=$grand;
  } else {
    $mod=!empty($s['mod']);
    foreach($entries as $e){ $hidden=!empty($e['hidden']); $approved=(!$mod)||!empty($e['approved']);
      if(!$admin){ if($hidden||!$approved) continue; $count++; }
      else { if(!$hidden && $approved) $count++; }
      $results[]=array('id'=>$e['id'],'text'=>(string)$e['text'],'name'=>(string)(isset($e['name'])?$e['name']:''),'up'=>count(isset($e['voters'])?$e['voters']:array()),'hidden'=>$hidden,'answered'=>!empty($e['answered']),'starred'=>!empty($e['starred']),'approved'=>$approved,'pending'=>($mod && !$approved && !$hidden)); }
    usort($results,function($a,$b){ if(($b['starred']?1:0)!==($a['starred']?1:0)) return ($b['starred']?1:0)-($a['starred']?1:0); return $b['up']-$a['up']; });
    $ex['mod']=$mod;
  }
  return array_merge(array('code'=>$s['code'],'type'=>$type,'prompt'=>$s['prompt'],'open'=>!empty($s['open']),
    'options'=>isset($s['options'])?$s['options']:array(),'multi'=>isset($s['multi'])?(int)$s['multi']:1,
    'results'=>$results,'count'=>$count,'isAdmin'=>$admin), $ex);
}
function qff_deck_public($s,$admin){
  $slides=isset($s['slides'])?$s['slides']:array(); $n=count($slides); $cur=(int)(isset($s['current'])?$s['current']:0);
  if($cur<0)$cur=0; if($cur>=$n)$cur=($n>0)?$n-1:0;
  if($n===0){ return array('code'=>$s['code'],'type'=>'deck','prompt'=>$s['prompt'],'open'=>!empty($s['open']),'results'=>array(),'count'=>0,'isAdmin'=>$admin,'deck'=>array('current'=>0,'total'=>0,'open'=>!empty($s['open']),'title'=>$s['prompt'])); }
  $slide=$slides[$cur]; $slide['code']=$s['code']; $slide['open']=!empty($s['open']);
  $pub=qff_live_public($slide,$admin);
  $pub['deck']=array('current'=>$cur,'total'=>$n,'open'=>!empty($s['open']),'title'=>$s['prompt']);
  return $pub;
}
function qff_profanity_list(){ return array(
  'fuck','fucking','fucker','shit','bullshit','bitch','bastard','asshole','dickhead','motherfucker','cunt','wanker','prick','slut','whore','faggot','nigger','retard','douche','jackass','dumbass','pussy',
  'pula','pizda','pizdă','muie','muist','curva','curvă','cur','fut','futu','fute','futut','căcat','cacat','rahat','dracu','dracului','bou','proasta','proastă','prost','tampit','tâmpit','javra','javră','coaie','gaozar','gãozar','sugi','pulă'
); }
function qff_profanity_mask($text){
  foreach(qff_profanity_list() as $w){ $q=preg_quote($w,'/');
    $text=preg_replace_callback('/(?<![\\p{L}])'.$q.'(?![\\p{L}])/iu', function($m){ $len=function_exists('mb_strlen')?mb_strlen($m[0]):strlen($m[0]); return str_repeat('*', max(3,$len)); }, $text);
  }
  return $text===null?'':$text;
}
function qff_live_build_entry($type,$cfg,$b,$now){
  $base=array('id'=>'e'.base_convert((string)(int)round($now*1000),10,36).bin2hex(random_bytes(2)),'ts'=>(int)round($now*1000),'hidden'=>false);
  if($type==='rating'){ $scale=(int)(isset($cfg['scale'])?$cfg['scale']:5); if($scale!==10)$scale=5; $min=($scale===5)?1:0; $val=(int)(isset($b['value'])?$b['value']:-999); if($val<$min||$val>$scale) qff_err('bad value'); $base['value']=$val; return $base; }
  if($type==='rank'){ $opts=isset($cfg['options'])?$cfg['options']:array(); $n=count($opts); $ord=(isset($b['order'])&&is_array($b['order']))?$b['order']:null; if(!$ord||count($ord)!==$n) qff_err('bad order'); $seen=array(); $clean=array(); foreach($ord as $idx){ $idx=(int)$idx; if($idx<0||$idx>=$n||isset($seen[$idx])) qff_err('bad order'); $seen[$idx]=1; $clean[]=$idx; } $base['order']=$clean; return $base; }
  if($type==='scale'){ $stmts=isset($cfg['statements'])?$cfg['statements']:array(); $m=count($stmts); $r=(isset($b['ratings'])&&is_array($b['ratings']))?$b['ratings']:null; if(!$r||count($r)!==$m) qff_err('bad ratings'); $clean=array(); foreach($r as $v){ $v=(int)$v; if($v<1||$v>5) qff_err('bad ratings'); $clean[]=$v; } $base['ratings']=$clean; return $base; }
  if($type==='points'){ $opts=isset($cfg['options'])?$cfg['options']:array(); $n=count($opts); $budget=(int)(isset($cfg['budget'])?$cfg['budget']:100); $al=(isset($b['alloc'])&&is_array($b['alloc']))?$b['alloc']:null; if(!$al||count($al)!==$n) qff_err('bad alloc'); $sa=0; $clean=array(); foreach($al as $p){ $p=(int)$p; if($p<0) qff_err('bad alloc'); $sa+=$p; $clean[]=$p; } if($sa>$budget) qff_err('over budget'); $base['alloc']=$clean; return $base; }
  $text=qff_clip(isset($b['text'])?$b['text']:'', $type==='qa'?240:40); if($text==='') qff_err('empty');
  if($type==='poll'){ if(!in_array($text, isset($cfg['options'])?$cfg['options']:array(), true)) qff_err('bad option'); }
  if(($type==='cloud'||$type==='qa') && !empty($cfg['filter'])) $text=qff_profanity_mask($text);
  $base['name']=qff_clip(isset($b['name'])?$b['name']:'',40); $base['text']=$text; $base['voters']=array();
  if($type==='qa') $base['approved']=empty($cfg['mod']);
  return $base;
}
function qff_safe_pid($p){ $p=preg_replace('/[^a-f0-9]/','',(string)$p); return substr($p,0,32); }
function qff_game_pub_player($p){ return array('id'=>$p['id'],'name'=>$p['name'],'avatar'=>isset($p['avatar'])?(int)$p['avatar']:0,'score'=>(int)$p['score']); }
function qff_game_leaderboard($s){ $ps=isset($s['players'])?$s['players']:array(); usort($ps,function($a,$b){ if($b['score']!==$a['score']) return $b['score']-$a['score']; return ((int)(isset($a['joined'])?$a['joined']:0))-((int)(isset($b['joined'])?$b['joined']:0)); }); return $ps; }
function qff_game_state($s,$pid,$admin){
  $phase=isset($s['phase'])?$s['phase']:'lobby'; $qi=(int)(isset($s['qIndex'])?$s['qIndex']:-1);
  $quiz=isset($s['quiz'])?$s['quiz']:array('questions'=>array()); $qs=isset($quiz['questions'])?$quiz['questions']:array(); $total=count($qs);
  $now=(int)round(microtime(true)*1000);
  $out=array('type'=>'game','code'=>$s['code'],'prompt'=>$s['prompt'],'phase'=>$phase,'qIndex'=>$qi,'total'=>$total,'open'=>!empty($s['open']),'count'=>count(isset($s['players'])?$s['players']:array()),'now'=>$now,'isAdmin'=>$admin);
  { $rx=isset($s['reactions'])?$s['reactions']:array(); $rec=array(); $cut=$now-6000; foreach($rx as $r){ if((int)(isset($r['ts'])?$r['ts']:0)>$cut) $rec[]=array('e'=>(int)$r['e'],'ts'=>(int)$r['ts']); } if($rec) $out['reactions']=array_slice($rec,-30); }
  $me=null; if($pid!==''){ foreach((isset($s['players'])?$s['players']:array()) as $p){ if($p['id']===$pid){ $me=$p; break; } } }
  if($me) $out['me']=array('id'=>$me['id'],'name'=>$me['name'],'avatar'=>(int)(isset($me['avatar'])?$me['avatar']:0),'score'=>(int)$me['score'],'streak'=>(int)$me['streak']);
  if(isset($s['teams']) && is_array($s['teams']) && count($s['teams'])>=2){
    $teams=$s['teams'];
    $out['teamList']=array(); foreach($teams as $ti=>$tm){ $out['teamList'][]=array('idx'=>$ti,'name'=>(string)$tm['name'],'emoji'=>(string)(isset($tm['emoji'])?$tm['emoji']:'⚑'),'color'=>(string)(isset($tm['color'])?$tm['color']:'#888888')); }
    $agg=array(); foreach($teams as $ti=>$tm){ $agg[$ti]=array('idx'=>$ti,'name'=>(string)$tm['name'],'emoji'=>(string)(isset($tm['emoji'])?$tm['emoji']:'⚑'),'color'=>(string)(isset($tm['color'])?$tm['color']:'#888888'),'score'=>0,'members'=>0); }
    foreach((isset($s['players'])?$s['players']:array()) as $p){ $ti=isset($p['team'])?(int)$p['team']:0; if(!isset($agg[$ti])) $ti=0; if(isset($agg[$ti])){ $agg[$ti]['score']+=(int)$p['score']; $agg[$ti]['members']++; } }
    $ts=array_values($agg); usort($ts,function($a,$b){ if($b['score']!==$a['score']) return $b['score']-$a['score']; return $a['idx']-$b['idx']; }); $out['teams']=$ts;
    if($me && isset($me['team'])){ $mt=(int)$me['team']; if(isset($teams[$mt])) $out['myTeam']=array('idx'=>$mt,'name'=>(string)$teams[$mt]['name'],'emoji'=>(string)(isset($teams[$mt]['emoji'])?$teams[$mt]['emoji']:'⚑'),'color'=>(string)(isset($teams[$mt]['color'])?$teams[$mt]['color']:'#888888')); }
  }
  if($phase==='lobby'){
    $players=array(); foreach((isset($s['players'])?$s['players']:array()) as $p) $players[]=array('id'=>$p['id'],'name'=>$p['name'],'avatar'=>(int)(isset($p['avatar'])?$p['avatar']:0));
    $out['players']=$players; return $out;
  }
  if(($phase==='question'||$phase==='reveal') && $qi>=0 && $qi<$total){
    $q=$qs[$qi]; $qStart=(int)(isset($s['qStart'])?$s['qStart']:0); $timeLimit=(int)$q['time'];
    $answered=0; foreach((isset($s['players'])?$s['players']:array()) as $p){ if(isset($p['answers'][$qi])) $answered++; }
    $qq=array('text'=>$q['text'],'time'=>$timeLimit,'type'=>$q['type'],'index'=>$qi,'qStart'=>$qStart,'answeredCount'=>$answered);
    if($phase==='question'){
      $qq['remaining']=max(0, $timeLimit*1000-($now-$qStart));
      if(in_array($q['type']??'', array('type','num'), true)){
        $qq['isText']=true; if(($q['type']??'')==='num') $qq['isNum']=true;
        if($me){ $my=isset($me['answers'][$qi])?$me['answers'][$qi]:null; $qq['answered']=($my!==null); if($my!==null) $qq['myText']=(string)(isset($my['text'])?$my['text']:''); }
      } else {
        $ans=array(); foreach($q['answers'] as $a) $ans[]=array('text'=>$a['text']); $qq['answers']=$ans;
        if($me){ $my=isset($me['answers'][$qi])?$me['answers'][$qi]:null; $qq['answered']=($my!==null); if($my!==null) $qq['myChoice']=(int)$my['choice']; }
      }
      $out['question']=$qq;
    } else {
      if(in_array($q['type']??'', array('type','num'), true)){
        $isnum=(($q['type']??'')==='num'); $canon=isset($q['answers'][0]['text'])?(string)$q['answers'][0]['text']:'';
        $grp=array(); $correctCount=0; $ansCount=0;
        foreach((isset($s['players'])?$s['players']:array()) as $p){ if(isset($p['answers'][$qi])){ $tx=trim((string)(isset($p['answers'][$qi]['text'])?$p['answers'][$qi]['text']:'')); if($tx==='') continue; $ansCount++; $ok=!empty($p['answers'][$qi]['correct']); if($ok) $correctCount++; $k=function_exists('mb_strtolower')?mb_strtolower($tx,'UTF-8'):strtolower($tx); if(!isset($grp[$k])) $grp[$k]=array('text'=>$tx,'count'=>0,'correct'=>$ok); $grp[$k]['count']++; } }
        $vals=array_values($grp); usort($vals,function($a,$b){ return $b['count']-$a['count']; });
        $subs=array(); foreach(array_slice($vals,0,12) as $g){ $subs[]=array('text'=>$g['text'],'count'=>(int)$g['count'],'correct'=>!empty($g['correct'])); }
        $qq['isText']=true; if($isnum) $qq['isNum']=true; $qq['canonical']=$canon; $qq['submissions']=$subs; $qq['correctCount']=$correctCount; $qq['answerCount']=$ansCount;
      } else {
        $correctIdx=-1; foreach($q['answers'] as $i=>$a){ if(!empty($a['correct'])){ $correctIdx=$i; break; } }
        $dist=array_fill(0,count($q['answers']),0);
        foreach((isset($s['players'])?$s['players']:array()) as $p){ if(isset($p['answers'][$qi])){ $c=(int)$p['answers'][$qi]['choice']; if($c>=0&&$c<count($dist)) $dist[$c]++; } }
        $ans=array(); foreach($q['answers'] as $i=>$a) $ans[]=array('text'=>$a['text'],'correct'=>($i===$correctIdx),'count'=>$dist[$i]);
        $qq['answers']=$ans; $qq['correctIndex']=$correctIdx;
      }
      $out['question']=$qq;
      $lb=qff_game_leaderboard($s); $top=array(); foreach(array_slice($lb,0,8) as $p) $top[]=qff_game_pub_player($p); $out['leaderboard']=$top;
      if($me){ $rank=1; foreach($lb as $i=>$p){ if($p['id']===$me['id']){ $rank=$i+1; break; } } $my=isset($me['answers'][$qi])?$me['answers'][$qi]:null;
        $out['myResult']=array('answered'=>($my!==null),'correct'=>($my!==null && !empty($my['correct'])),'points'=>($my!==null?(int)$my['points']:0),'score'=>(int)$me['score'],'rank'=>$rank,'streak'=>(int)$me['streak']); }
    }
    return $out;
  }
  if($phase==='final'){
    $lb=qff_game_leaderboard($s); $top=array(); foreach($lb as $p) $top[]=qff_game_pub_player($p); $out['leaderboard']=array_slice($top,0,20);
    if($me){ $rank=1; foreach($lb as $i=>$p){ if($p['id']===$me['id']){ $rank=$i+1; break; } } $out['myRank']=$rank; }
    $out['totalPlayers']=count(isset($s['players'])?$s['players']:array()); return $out;
  }
  return $out;
}
function qff_assign_state($s,$pid,$admin){
  $quiz=isset($s['quiz'])?$s['quiz']:array('questions'=>array()); $qs=isset($quiz['questions'])?$quiz['questions']:array(); $total=count($qs);
  $players=isset($s['players'])?$s['players']:array();
  $out=array('type'=>'assign','code'=>$s['code'],'prompt'=>$s['prompt'],'total'=>$total,'open'=>!empty($s['open']),'count'=>count($players),'isAdmin'=>$admin);
  if($admin){
    $done=0; $sum=0; foreach($players as $p){ if(!empty($p['done'])) $done++; $sum+=(int)$p['score']; }
    $out['doneCount']=$done; $out['avgScore']=count($players)?(int)round($sum/count($players)):0;
    $fin=array(); foreach($players as $p){ if(!empty($p['done'])) $fin[]=$p; }
    usort($fin,function($a,$b){ if($b['score']!==$a['score']) return $b['score']-$a['score']; return ((int)(isset($a['finished'])?$a['finished']:0))-((int)(isset($b['finished'])?$b['finished']:0)); });
    $lb=array(); foreach(array_slice($fin,0,12) as $p) $lb[]=array('name'=>$p['name'],'avatar'=>(int)(isset($p['avatar'])?$p['avatar']:0),'score'=>(int)$p['score'],'correct'=>(int)(isset($p['correct'])?$p['correct']:0));
    $out['leaderboard']=$lb; return $out;
  }
  $me=null; if($pid!==''){ foreach($players as $p){ if($p['id']===$pid){ $me=$p; break; } } }
  if(!$me){ $out['joined']=false; return $out; }
  $answered=count(isset($me['answers'])?$me['answers']:array());
  $out['me']=array('name'=>$me['name'],'avatar'=>(int)(isset($me['avatar'])?$me['avatar']:0),'score'=>(int)$me['score'],'answered'=>$answered);
  if($answered>=$total || !empty($me['done'])){
    $fin=array(); foreach($players as $p){ if(!empty($p['done']) || count(isset($p['answers'])?$p['answers']:array())>=$total) $fin[]=$p; }
    usort($fin,function($a,$b){ return $b['score']-$a['score']; });
    $rank=1; foreach($fin as $i=>$p){ if($p['id']===$me['id']){ $rank=$i+1; break; } }
    $out['done']=true; $out['result']=array('score'=>(int)$me['score'],'correct'=>(int)(isset($me['correct'])?$me['correct']:0),'total'=>$total,'rank'=>$rank,'finishers'=>count($fin));
    return $out;
  }
  $q=$qs[$answered]; $ans=array(); if(!in_array($q['type']??'', array('type','num'), true)){ foreach($q['answers'] as $a) $ans[]=array('text'=>$a['text']); }
  $out['current']=array('index'=>$answered,'total'=>$total,'text'=>$q['text'],'type'=>$q['type'],'points'=>(int)$q['points'],'answers'=>$ans,'isText'=>in_array($q['type']??'', array('type','num'), true),'isNum'=>(($q['type']??'')==='num'));
  return $out;
}

/* =========================== API ROUTER =========================== */
$action = isset($_GET['api']) ? $_GET['api'] : null;
if($action !== null){
  $cfg=qff_cfg();
  $moderate=!empty($cfg['moderate']);

  if($action==='quizzes'){
    $list=array();
    $files=glob($QDIR.'/*.json'); if(!$files) $files=array();
    foreach($files as $f){ $j=json_decode((string)file_get_contents($f),true); if(is_array($j)&&isset($j['id'])) $list[]=$j; }
    qff_json(array('quizzes'=>$list));
  }

  if($action==='quiz'){
    $id=qff_safe_id(isset($_GET['id'])?$_GET['id']:''); if($id==='') qff_err('bad id');
    $f=$QDIR.'/'.$id.'.json'; if(!file_exists($f)) qff_err('not found',404);
    qff_json(array('quiz'=>json_decode((string)file_get_contents($f),true)));
  }

  if($action==='save'){
    $b=qff_body(); qff_check_csrf($b); qff_require_admin();
    $q=qff_norm_quiz(isset($b['quiz'])?$b['quiz']:null);
    $f=$QDIR.'/'.$q['id'].'.json';
    if(!qff_atomic($f, json_encode($q, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT))) qff_err('write failed',500);
    qff_json(array('quiz'=>$q,'ok'=>true));
  }

  if($action==='delete'){
    $b=qff_body(); qff_check_csrf($b); qff_require_admin();
    $id=qff_safe_id(isset($b['id'])?$b['id']:''); if($id==='') qff_err('bad id');
    $f=$QDIR.'/'.$id.'.json'; if(file_exists($f)) @unlink($f);
    qff_json(array('ok'=>true));
  }

  if($action==='feedback'){
    if($_SERVER['REQUEST_METHOD']==='POST'){
      $b=qff_body();
      $now=time();
      if(!empty($_SESSION['qff_fb_last']) && ($now-$_SESSION['qff_fb_last'])<FB_COOLDOWN) qff_err('please wait a moment',429);
      if(!empty($b['website'])) qff_err('spam',400); /* honeypot */
      $rating=(int)(isset($b['rating'])?$b['rating']:0); if($rating<1||$rating>5) qff_err('bad rating');
      $msg=qff_clip(isset($b['message'])?$b['message']:'',MAX_MSG); if($msg==='') qff_err('empty message');
      $name=qff_clip(isset($b['name'])?$b['name']:'',MAX_NAME);
      $qid=qff_safe_id(isset($b['quizId'])?$b['quizId']:'');
      $qtitle=qff_clip(isset($b['quizTitle'])?$b['quizTitle']:'',MAX_TITLE);
      $entry=array('ts'=>(int)round(microtime(true)*1000),'status'=>($moderate?'pend':'pub'),
        'quizId'=>$qid,'quizTitle'=>$qtitle,'name'=>$name,'rating'=>$rating,'message'=>$msg);
      $fh=fopen($FB,'a');
      if($fh){ if(flock($fh,LOCK_EX)){ fwrite($fh, qff_fb_line($entry)."\n"); flock($fh,LOCK_UN); } fclose($fh); }
      $_SESSION['qff_fb_last']=$now;
      qff_json(array('ok'=>true,'entry'=>$entry));
    } else {
      $scope=qff_safe_id(isset($_GET['quiz'])?$_GET['quiz']:'');
      $admin=qff_is_admin();
      $rows=qff_fb_all();
      $rows=array_values(array_filter($rows,function($r) use($admin,$scope){
        if(!$admin && $r['status']==='pend') return false;
        if($scope!=='' && $r['quizId']!==$scope) return false;
        return true;
      }));
      usort($rows,function($a,$b){ return $b['ts']-$a['ts']; });
      qff_json(array('feedback'=>array_slice($rows,0,500)));
    }
  }

  if($action==='fbdelete'){
    $b=qff_body(); qff_check_csrf($b); qff_require_admin();
    $ts=(int)(isset($b['ts'])?$b['ts']:0);
    $rows=array_values(array_filter(qff_fb_all(),function($r) use($ts){ return $r['ts']!==$ts; }));
    qff_fb_write_all($rows); qff_json(array('ok'=>true));
  }

  if($action==='fbapprove'){
    $b=qff_body(); qff_check_csrf($b); qff_require_admin();
    $ts=(int)(isset($b['ts'])?$b['ts']:0);
    $rows=qff_fb_all();
    foreach($rows as $i=>$r){ if($r['ts']===$ts) $rows[$i]['status']='pub'; }
    qff_fb_write_all($rows); qff_json(array('ok'=>true));
  }

  if($action==='login'){
    $b=qff_body(); $cfg=qff_cfg(); $pw=(string)(isset($b['password'])?$b['password']:'');
    if((isset($cfg['admin_hash'])?$cfg['admin_hash']:'')==='') qff_err('no admin set',409);
    if(!password_verify($pw,$cfg['admin_hash'])){ usleep(300000); qff_err('wrong password',401); }
    session_regenerate_id(true); $_SESSION['qff_admin']=true;
    qff_json(array('ok'=>true,'csrf'=>qff_csrf()));
  }

  if($action==='logout'){
    $_SESSION['qff_admin']=false; unset($_SESSION['qff_admin']);
    qff_json(array('ok'=>true));
  }

  if($action==='setup_admin'){
    $b=qff_body(); $cfg=qff_cfg();
    if((isset($cfg['admin_hash'])?$cfg['admin_hash']:'')!=='') qff_err('admin already set',409);
    $pw=(string)(isset($b['password'])?$b['password']:''); if(strlen($pw)<6) qff_err('password too short');
    $cfg['admin_hash']=password_hash($pw,PASSWORD_DEFAULT);
    qff_cfg_save($cfg);
    session_regenerate_id(true); $_SESSION['qff_admin']=true;
    qff_json(array('ok'=>true,'csrf'=>qff_csrf()));
  }


  if($action==='live_create'){
    $b=qff_body(); qff_check_csrf($b); qff_require_admin();
    $type=isset($b['type'])?$b['type']:''; if(!in_array($type,array('cloud','poll','qa','game','assign','rating','rank','scale','points'),true)) qff_err('bad type');
    if($type==='game'){
      $quiz=qff_norm_quiz(isset($b['quiz'])?$b['quiz']:null);
      $s=array('code'=>qff_gen_code(),'type'=>'game','prompt'=>$quiz['title'],'quiz'=>$quiz,'open'=>true,'phase'=>'lobby','qIndex'=>-1,'qStart'=>0,'qStarts'=>array(),'created'=>(int)round(microtime(true)*1000),'players'=>array());
      $tms=(isset($b['teams'])&&is_array($b['teams']))?$b['teams']:array(); $teams=array();
      foreach($tms as $tm){ if(!is_array($tm)) continue; $teams[]=array('name'=>qff_clip(isset($tm['name'])?$tm['name']:'Echipă',20),'emoji'=>qff_clip(isset($tm['emoji'])?$tm['emoji']:'⚑',8),'color'=>qff_clip(isset($tm['color'])?$tm['color']:'#888888',9)); if(count($teams)>=4) break; }
      if(count($teams)>=2) $s['teams']=$teams;
      if(!qff_live_write($s)) qff_err('write failed',500);
      qff_json(array('ok'=>true,'session'=>qff_game_state($s,'',true)));
    }
    if($type==='assign'){
      $quiz=qff_norm_quiz(isset($b['quiz'])?$b['quiz']:null);
      $s=array('code'=>qff_gen_code(),'type'=>'assign','prompt'=>$quiz['title'],'quiz'=>$quiz,'open'=>true,'created'=>(int)round(microtime(true)*1000),'players'=>array());
      if(!qff_live_write($s)) qff_err('write failed',500);
      qff_json(array('ok'=>true,'session'=>qff_assign_state($s,'',true)));
    }
    if($type==='deck'){
      $raw=(isset($b['slides'])&&is_array($b['slides']))?$b['slides']:array(); $slides=array();
      foreach($raw as $sl){ if(!is_array($sl)) continue; $st=isset($sl['type'])?$sl['type']:'';
        if(!in_array($st,array('cloud','poll','qa','rating','rank','scale','points'),true)) continue;
        $sp=qff_clip(isset($sl['prompt'])?$sl['prompt']:'',200); if($sp==='') continue;
        $so=array(); if($st==='poll'||$st==='rank'||$st==='points'){ $ro=(isset($sl['options'])&&is_array($sl['options']))?$sl['options']:array(); foreach($ro as $o){ $o=qff_clip($o,80); if($o!=='') $so[]=$o; } $so=array_slice($so,0,$st==='poll'?10:8); if(count($so)<2) continue; }
        $sst=array(); if($st==='scale'){ $rs=(isset($sl['statements'])&&is_array($sl['statements']))?$sl['statements']:array(); foreach($rs as $o){ $o=qff_clip($o,80); if($o!=='') $sst[]=$o; } $sst=array_slice($sst,0,6); if(count($sst)<2) continue; }
        $ssc=(int)(isset($sl['scale'])?$sl['scale']:5); if($ssc!==10)$ssc=5;
        $sbd=(int)(isset($sl['budget'])?$sl['budget']:100); if($sbd<10||$sbd>1000)$sbd=100;
        $sm=(int)(isset($sl['multi'])?$sl['multi']:1); if($sm<1)$sm=1; if($sm>10)$sm=10;
        $slides[]=array('type'=>$st,'prompt'=>$sp,'options'=>$so,'statements'=>$sst,'scale'=>$ssc,'budget'=>$sbd,'multi'=>$sm,'mod'=>!empty($sl['mod']),'filter'=>!empty($sl['filter']),'entries'=>array());
      }
      if(count($slides)<1) qff_err('need slides'); $slides=array_slice($slides,0,50);
      $title=qff_clip(isset($b['prompt'])?$b['prompt']:'',200); if($title==='') $title='Prezentare';
      $s=array('code'=>qff_gen_code(),'type'=>'deck','prompt'=>$title,'slides'=>$slides,'current'=>0,'open'=>true,'created'=>(int)round(microtime(true)*1000));
      if(!qff_live_write($s)) qff_err('write failed',500);
      qff_json(array('ok'=>true,'session'=>qff_deck_public($s,true)));
    }
    $prompt=qff_clip(isset($b['prompt'])?$b['prompt']:'',200); if($prompt==='') qff_err('missing prompt');
    $opts=array();
    if($type==='poll'||$type==='rank'||$type==='points'){ $raw=(isset($b['options'])&&is_array($b['options']))?$b['options']:array(); foreach($raw as $o){ $o=qff_clip($o,80); if($o!=='') $opts[]=$o; } $opts=array_slice($opts,0,$type==='poll'?10:8); if(count($opts)<2) qff_err('need 2 options'); }
    $stmts=array();
    if($type==='scale'){ $raw=(isset($b['statements'])&&is_array($b['statements']))?$b['statements']:array(); foreach($raw as $o){ $o=qff_clip($o,80); if($o!=='') $stmts[]=$o; } $stmts=array_slice($stmts,0,6); if(count($stmts)<2) qff_err('need 2 statements'); }
    $multi=(int)(isset($b['multi'])?$b['multi']:1); if($multi<1)$multi=1; if($multi>10)$multi=10;
    $scale=(int)(isset($b['scale'])?$b['scale']:5); if($scale!==10)$scale=5;
    $budget=(int)(isset($b['budget'])?$b['budget']:100); if($budget<10||$budget>1000)$budget=100;
    $s=array('code'=>qff_gen_code(),'type'=>$type,'prompt'=>$prompt,'options'=>$opts,'statements'=>$stmts,'multi'=>$multi,'scale'=>$scale,'budget'=>$budget,'mod'=>!empty($b['mod']),'filter'=>!empty($b['filter']),'open'=>true,'created'=>(int)round(microtime(true)*1000),'entries'=>array());
    if(!qff_live_write($s)) qff_err('write failed',500);
    qff_json(array('ok'=>true,'session'=>qff_live_public($s,true)));
  }
  if($action==='live_get'){
    $code=qff_safe_code(isset($_GET['code'])?$_GET['code']:''); if($code==='') qff_err('bad code');
    $s=qff_live_load($code); if(!$s) qff_err('not found',404);
    if(($s['type']??'')==='assign') qff_json(array('session'=>qff_assign_state($s, qff_safe_pid(isset($_GET['pid'])?$_GET['pid']:''), qff_is_admin())));
    if(($s['type']??'')==='game') qff_json(array('session'=>qff_game_state($s, qff_safe_pid(isset($_GET['pid'])?$_GET['pid']:''), qff_is_admin())));
    if(($s['type']??'')==='deck') qff_json(array('session'=>qff_deck_public($s, qff_is_admin())));
    qff_json(array('session'=>qff_live_public($s,qff_is_admin())));
  }
  if($action==='live_submit'){
    $b=qff_body();
    $code=qff_safe_code(isset($b['code'])?$b['code']:''); if($code==='') qff_err('bad code');
    if(!empty($b['website'])) qff_err('spam');
    $now=microtime(true); $last=isset($_SESSION['live_last'][$code])?$_SESSION['live_last'][$code]:0;
    if(($now-$last)<1.0) qff_err('slow down',429);
    $pre=qff_live_load($code); if(!$pre) qff_err('not found',404);
    if(empty($pre['open'])) qff_err('closed',423);
    if(($pre['type']??'')==='deck'){
      $slides=isset($pre['slides'])?$pre['slides']:array(); $n=count($slides); $cur=(int)(isset($pre['current'])?$pre['current']:0);
      $ci=(int)(isset($b['slide'])?$b['slide']:$cur); if($ci<0||$ci>=$n) qff_err('bad slide'); if($ci!==$cur) qff_err('slide changed',409);
      $slide=$slides[$cur]; $stype=isset($slide['type'])?$slide['type']:''; if(!in_array($stype,array('cloud','poll','qa','rating','rank','scale','points'),true)) qff_err('bad slide type');
      $entry=qff_live_build_entry($stype,$slide,$b,$now);
      list($s,$st)=qff_live_mutate($code,function($s) use($entry,$cur){ if(empty($s['open'])) return false; if(!isset($s['slides'][$cur])) return false; if(!isset($s['slides'][$cur]['entries'])) $s['slides'][$cur]['entries']=array(); if(count($s['slides'][$cur]['entries'])>=5000) return false; $s['slides'][$cur]['entries'][]=$entry; return $s; });
      if($st!=='ok') qff_err('could not save', $st==='reject'?423:500);
      $_SESSION['live_last'][$code]=$now; qff_json(array('ok'=>true,'session'=>qff_deck_public($s,qff_is_admin())));
    }
    $type=$pre['type'];
    if($type==='rating'){
      $scale=(int)(isset($pre['scale'])?$pre['scale']:5); if($scale!==10)$scale=5; $min=($scale===5)?1:0;
      $val=(int)(isset($b['value'])?$b['value']:-999); if($val<$min||$val>$scale) qff_err('bad value');
      $entry=array('id'=>'e'.base_convert((string)(int)round($now*1000),10,36).bin2hex(random_bytes(2)),'ts'=>(int)round($now*1000),'value'=>$val,'hidden'=>false);
      list($s,$st)=qff_live_mutate($code,function($s) use($entry){ if(empty($s['open'])) return false; if(!isset($s['entries'])) $s['entries']=array(); if(count($s['entries'])>=5000) return false; $s['entries'][]=$entry; return $s; });
      if($st!=='ok') qff_err('could not save', $st==='reject'?423:500);
      $_SESSION['live_last'][$code]=$now; qff_json(array('ok'=>true,'session'=>qff_live_public($s,qff_is_admin())));
    }
    if($type==='rank'){
      $opts=isset($pre['options'])?$pre['options']:array(); $n=count($opts);
      $ord=(isset($b['order'])&&is_array($b['order']))?$b['order']:null; if(!$ord||count($ord)!==$n) qff_err('bad order');
      $seen=array(); $clean=array(); foreach($ord as $idx){ $idx=(int)$idx; if($idx<0||$idx>=$n||isset($seen[$idx])) qff_err('bad order'); $seen[$idx]=1; $clean[]=$idx; }
      $entry=array('id'=>'e'.base_convert((string)(int)round($now*1000),10,36).bin2hex(random_bytes(2)),'ts'=>(int)round($now*1000),'order'=>$clean,'hidden'=>false);
      list($s,$st)=qff_live_mutate($code,function($s) use($entry){ if(empty($s['open'])) return false; if(!isset($s['entries'])) $s['entries']=array(); if(count($s['entries'])>=5000) return false; $s['entries'][]=$entry; return $s; });
      if($st!=='ok') qff_err('could not save', $st==='reject'?423:500);
      $_SESSION['live_last'][$code]=$now; qff_json(array('ok'=>true,'session'=>qff_live_public($s,qff_is_admin())));
    }
    if($type==='scale'){
      $stmts=isset($pre['statements'])?$pre['statements']:array(); $m=count($stmts);
      $r=(isset($b['ratings'])&&is_array($b['ratings']))?$b['ratings']:null; if(!$r||count($r)!==$m) qff_err('bad ratings');
      $clean=array(); foreach($r as $v){ $v=(int)$v; if($v<1||$v>5) qff_err('bad ratings'); $clean[]=$v; }
      $entry=array('id'=>'e'.base_convert((string)(int)round($now*1000),10,36).bin2hex(random_bytes(2)),'ts'=>(int)round($now*1000),'ratings'=>$clean,'hidden'=>false);
      list($s,$st)=qff_live_mutate($code,function($s) use($entry){ if(empty($s['open'])) return false; if(!isset($s['entries'])) $s['entries']=array(); if(count($s['entries'])>=5000) return false; $s['entries'][]=$entry; return $s; });
      if($st!=='ok') qff_err('could not save', $st==='reject'?423:500);
      $_SESSION['live_last'][$code]=$now; qff_json(array('ok'=>true,'session'=>qff_live_public($s,qff_is_admin())));
    }
    if($type==='points'){
      $opts=isset($pre['options'])?$pre['options']:array(); $n=count($opts); $budget=(int)(isset($pre['budget'])?$pre['budget']:100);
      $al=(isset($b['alloc'])&&is_array($b['alloc']))?$b['alloc']:null; if(!$al||count($al)!==$n) qff_err('bad alloc');
      $sa=0; $clean=array(); foreach($al as $p){ $p=(int)$p; if($p<0) qff_err('bad alloc'); $sa+=$p; $clean[]=$p; } if($sa>$budget) qff_err('over budget');
      $entry=array('id'=>'e'.base_convert((string)(int)round($now*1000),10,36).bin2hex(random_bytes(2)),'ts'=>(int)round($now*1000),'alloc'=>$clean,'hidden'=>false);
      list($s,$st)=qff_live_mutate($code,function($s) use($entry){ if(empty($s['open'])) return false; if(!isset($s['entries'])) $s['entries']=array(); if(count($s['entries'])>=5000) return false; $s['entries'][]=$entry; return $s; });
      if($st!=='ok') qff_err('could not save', $st==='reject'?423:500);
      $_SESSION['live_last'][$code]=$now; qff_json(array('ok'=>true,'session'=>qff_live_public($s,qff_is_admin())));
    }
    $text=qff_clip(isset($b['text'])?$b['text']:'', $type==='qa'?240:40); if($text==='') qff_err('empty');
    if($type==='poll'){ if(!in_array($text, isset($pre['options'])?$pre['options']:array(), true)) qff_err('bad option'); }
    if(($type==='cloud'||$type==='qa') && !empty($pre['filter'])) $text=qff_profanity_mask($text);
    $name=qff_clip(isset($b['name'])?$b['name']:'',40);
    $entry=array('id'=>'e'.base_convert((string)(int)round($now*1000),10,36).bin2hex(random_bytes(2)),'ts'=>(int)round($now*1000),'name'=>$name,'text'=>$text,'hidden'=>false,'voters'=>array(),'approved'=>empty($pre['mod']));
    list($s,$st)=qff_live_mutate($code,function($s) use($entry){ if(empty($s['open'])) return false; if(!isset($s['entries'])) $s['entries']=array(); if(count($s['entries'])>=5000) return false; $s['entries'][]=$entry; return $s; });
    if($st!=='ok') qff_err('could not save', $st==='reject'?423:500);
    $_SESSION['live_last'][$code]=$now;
    qff_json(array('ok'=>true,'id'=>$entry['id'],'session'=>qff_live_public($s,qff_is_admin())));
  }
  if($action==='live_vote'){
    $b=qff_body();
    $code=qff_safe_code(isset($b['code'])?$b['code']:''); $id=preg_replace('/[^a-z0-9]/','',(string)(isset($b['id'])?$b['id']:''));
    $voter=substr(preg_replace('/[^a-z0-9]/i','',(string)(isset($b['voter'])?$b['voter']:'')),0,40);
    if($code===''||$id===''||$voter==='') qff_err('bad request');
    list($s,$st)=qff_live_mutate($code,function($s) use($id,$voter){
      $tp=($s['type']??'');
      if($tp==='deck'){ $cur=(int)(isset($s['current'])?$s['current']:0); if(!isset($s['slides'][$cur])||($s['slides'][$cur]['type']??'')!=='qa') return false; $ch=false; foreach($s['slides'][$cur]['entries'] as $i=>$e){ if($e['id']===$id){ $v=isset($e['voters'])?$e['voters']:array(); if(!in_array($voter,$v,true)){ $v[]=$voter; $s['slides'][$cur]['entries'][$i]['voters']=$v; $ch=true; } break; } } return $ch?$s:false; }
      if($tp!=='qa') return false; $ch=false; foreach($s['entries'] as $i=>$e){ if($e['id']===$id){ $v=isset($e['voters'])?$e['voters']:array(); if(!in_array($voter,$v,true)){ $v[]=$voter; $s['entries'][$i]['voters']=$v; $ch=true; } break; } } return $ch?$s:false; });
    qff_json(array('ok'=>true));
  }
  if($action==='live_control'){
    $b=qff_body(); qff_check_csrf($b); qff_require_admin();
    $code=qff_safe_code(isset($b['code'])?$b['code']:''); $act=isset($b['action'])?$b['action']:''; $id=preg_replace('/[^a-z0-9]/','',(string)(isset($b['id'])?$b['id']:''));
    if($code==='') qff_err('bad code');
    if($act==='delete_session'){ $f=qff_live_path($code); if(file_exists($f)) @unlink($f); qff_json(array('ok'=>true,'deleted'=>true)); }
    list($s,$st)=qff_live_mutate($code,function($s) use($act,$id){
      if(($s['type']??'')==='deck'){
        $n=count(isset($s['slides'])?$s['slides']:array()); $cur=(int)(isset($s['current'])?$s['current']:0);
        if($act==='open'){ $s['open']=true; }
        else if($act==='close'){ $s['open']=false; }
        else if($act==='next'){ $s['current']=($cur+1<$n)?$cur+1:(($n>0)?$n-1:0); }
        else if($act==='prev'){ $s['current']=($cur-1>=0)?$cur-1:0; }
        else if($act==='goto'){ $g=(int)$id; $s['current']=max(0,min($g,($n>0)?$n-1:0)); }
        else if($act==='clear'){ if(isset($s['slides'][$cur])) $s['slides'][$cur]['entries']=array(); }
        else if($act==='hide'||$act==='show'||$act==='delete'){ if(isset($s['slides'][$cur]['entries'])){ $out=array(); foreach($s['slides'][$cur]['entries'] as $e){ if($e['id']===$id){ if($act==='delete') continue; $e['hidden']=($act==='hide'); } $out[]=$e; } $s['slides'][$cur]['entries']=$out; } }
        else if($act==='answer'||$act==='star'){ $k=($act==='answer')?'answered':'starred'; if(isset($s['slides'][$cur]['entries'])){ foreach($s['slides'][$cur]['entries'] as $i=>$e){ if($e['id']===$id){ $s['slides'][$cur]['entries'][$i][$k]=empty($e[$k]); break; } } } }
        else if($act==='approve'){ if(isset($s['slides'][$cur]['entries'])){ foreach($s['slides'][$cur]['entries'] as $i=>$e){ if($e['id']===$id){ $s['slides'][$cur]['entries'][$i]['approved']=empty($e['approved']); break; } } } }
        else return false;
        return $s;
      }
      if($act==='open'){ $s['open']=true; }
      else if($act==='close'){ $s['open']=false; }
      else if($act==='clear'){ $s['entries']=array(); }
      else if($act==='hide'||$act==='show'||$act==='delete'){ $out=array(); foreach($s['entries'] as $e){ if($e['id']===$id){ if($act==='delete') continue; $e['hidden']=($act==='hide'); } $out[]=$e; } $s['entries']=$out; }
      else if($act==='answer'||$act==='star'){ $k=($act==='answer')?'answered':'starred'; foreach($s['entries'] as $i=>$e){ if($e['id']===$id){ $s['entries'][$i][$k]=empty($e[$k]); break; } } }
      else if($act==='approve'){ foreach($s['entries'] as $i=>$e){ if($e['id']===$id){ $s['entries'][$i]['approved']=empty($e['approved']); break; } } }
      else if($act==='start'){ if(($s['type']??'')!=='game') return false; $t=(int)round(microtime(true)*1000); $s['phase']='question'; $s['qIndex']=0; $s['qStart']=$t; if(!isset($s['qStarts'])||!is_array($s['qStarts'])) $s['qStarts']=array(); $s['qStarts'][0]=$t; $s['open']=false; }
      else if($act==='reveal'||$act==='skip'){ if(($s['type']??'')!=='game') return false; $s['phase']='reveal'; }
      else if($act==='next'){ if(($s['type']??'')!=='game') return false; $n=(int)$s['qIndex']+1; if($n<count($s['quiz']['questions'])){ $t=(int)round(microtime(true)*1000); $s['qIndex']=$n; $s['qStart']=$t; if(!isset($s['qStarts'])||!is_array($s['qStarts'])) $s['qStarts']=array(); $s['qStarts'][$n]=$t; $s['phase']='question'; } else { $s['phase']='final'; } }
      else if($act==='end'){ if(($s['type']??'')!=='game') return false; $s['phase']='final'; }
      else if($act==='restart'){ if(($s['type']??'')!=='game') return false; foreach($s['players'] as $i=>$p){ $s['players'][$i]['score']=0; $s['players'][$i]['streak']=0; $s['players'][$i]['best']=0; $s['players'][$i]['correct']=0; $s['players'][$i]['answers']=array(); } $s['phase']='lobby'; $s['qIndex']=-1; $s['qStarts']=array(); $s['open']=true; }
      else if($act==='kick'){ if(($s['type']??'')!=='game') return false; $out=array(); foreach($s['players'] as $p){ if($p['id']!==$id) $out[]=$p; } $s['players']=$out; }
      else return false;
      return $s;
    });
    if($st!=='ok') qff_err($st==='missing'?'not found':'bad action', $st==='missing'?404:400);
    $rt=($s['type']??''); $rsess = $rt==='game' ? qff_game_state($s,'',true) : ($rt==='assign' ? qff_assign_state($s,'',true) : ($rt==='deck' ? qff_deck_public($s,true) : qff_live_public($s,true)));
    qff_json(array('ok'=>true,'session'=>$rsess));
  }
  if($action==='live_join'){
    $b=qff_body();
    $code=qff_safe_code(isset($b['code'])?$b['code']:''); if($code==='') qff_err('bad code');
    $name=qff_clip(isset($b['name'])?$b['name']:'',20); if($name==='') qff_err('name required');
    $avatar=(int)(isset($b['avatar'])?$b['avatar']:0); if($avatar<0||$avatar>63) $avatar=0;
    $team=(int)(isset($b['team'])?$b['team']:-1);
    $pid=bin2hex(random_bytes(8));
    $pre=qff_live_load($code); $ptype=$pre?($pre['type']??''):'';
    list($s,$st)=qff_live_mutate($code,function($s) use($name,$avatar,$pid,$team){
      $tp=($s['type']??'');
      if($tp!=='game' && $tp!=='assign') return false;
      if($tp==='game'){ if(($s['phase']??'')!=='lobby' || empty($s['open'])) return false; }
      else { if(empty($s['open'])) return false; }
      if(!isset($s['players'])) $s['players']=array();
      if(count($s['players'])>=500) return false;
      $pl=array('id'=>$pid,'name'=>$name,'avatar'=>$avatar,'score'=>0,'streak'=>0,'best'=>0,'correct'=>0,'joined'=>(int)round(microtime(true)*1000),'answers'=>array(),'done'=>false,'finished'=>0);
      if(isset($s['teams'])&&is_array($s['teams'])){ $nt=count($s['teams']); $pl['team']=($team>=0&&$team<$nt)?$team:0; }
      $s['players'][]=$pl;
      return $s;
    });
    if($st!=='ok') qff_err($st==='reject'?'closed':'not found', $st==='reject'?423:404);
    qff_json(array('ok'=>true,'pid'=>$pid,'session'=>($ptype==='assign'?qff_assign_state($s,$pid,false):qff_game_state($s,$pid,false))));
  }
  if($action==='live_answer'){
    $b=qff_body();
    $code=qff_safe_code(isset($b['code'])?$b['code']:''); $pid=qff_safe_pid(isset($b['pid'])?$b['pid']:''); $choice=(int)(isset($b['choice'])?$b['choice']:-1); $atext=isset($b['text'])?(string)$b['text']:'';
    if($code===''||$pid==='') qff_err('bad request');
    $pre0=qff_live_load($code); if(!$pre0) qff_err('not found',404);
    if(($pre0['type']??'')==='assign'){
      $qi=(int)(isset($b['qi'])?$b['qi']:-1); $fb=array('correct'=>false,'correctIndex'=>-1,'points'=>0,'done'=>false);
      list($s,$st)=qff_live_mutate($code,function($s) use($pid,$choice,$atext,$qi,&$fb){
        if(($s['type']??'')!=='assign' || empty($s['open'])) return false;
        $qs=$s['quiz']['questions']; $total=count($qs);
        $pidx=-1; foreach($s['players'] as $i=>$p){ if($p['id']===$pid){ $pidx=$i; break; } } if($pidx<0) return false;
        $answered=count(isset($s['players'][$pidx]['answers'])?$s['players'][$pidx]['answers']:array());
        if($qi!==$answered || $qi<0 || $qi>=$total) return false;
        $q=$qs[$qi];
        if(($q['type']??'')==='type'){
          $acc=array(); foreach($q['answers'] as $aa){ $acc[]=$aa['text']; } $tx=qff_clip($atext,90);
          $correct=qff_text_match($tx,$acc); $pts=0;
          if($correct){ $pts=(int)$q['points']; $st0=(int)$s['players'][$pidx]['streak']; if($st0>=1) $pts+=min($st0,5)*100; $s['players'][$pidx]['streak']=$st0+1; if($s['players'][$pidx]['streak']>(int)$s['players'][$pidx]['best']) $s['players'][$pidx]['best']=$s['players'][$pidx]['streak']; $s['players'][$pidx]['correct']=(int)$s['players'][$pidx]['correct']+1; }
          else { $s['players'][$pidx]['streak']=0; }
          $now=(int)round(microtime(true)*1000); $s['players'][$pidx]['score']=(int)$s['players'][$pidx]['score']+$pts;
          $s['players'][$pidx]['answers'][$qi]=array('choice'=>-1,'text'=>$tx,'ts'=>$now,'correct'=>$correct,'points'=>$pts);
          $done=(($qi+1)>=$total); if($done){ $s['players'][$pidx]['done']=true; $s['players'][$pidx]['finished']=$now; }
          $fb=array('correct'=>$correct,'correctIndex'=>-1,'points'=>$pts,'done'=>$done,'canonical'=>(isset($q['answers'][0]['text'])?(string)$q['answers'][0]['text']:''));
          return $s;
        }
        if(($q['type']??'')==='num'){
          $tx=qff_clip($atext,90); $r=qff_num_score($tx,(isset($q['answers'][0]['text'])?$q['answers'][0]['text']:''),isset($q['tol'])?$q['tol']:0);
          $correct=$r['ok']; $pts=0;
          if($correct){ $pts=(int)round((int)$q['points']*$r['close']); $st0=(int)$s['players'][$pidx]['streak']; if($st0>=1) $pts+=min($st0,5)*100; $s['players'][$pidx]['streak']=$st0+1; if($s['players'][$pidx]['streak']>(int)$s['players'][$pidx]['best']) $s['players'][$pidx]['best']=$s['players'][$pidx]['streak']; $s['players'][$pidx]['correct']=(int)$s['players'][$pidx]['correct']+1; }
          else { $s['players'][$pidx]['streak']=0; }
          $now=(int)round(microtime(true)*1000); $s['players'][$pidx]['score']=(int)$s['players'][$pidx]['score']+$pts;
          $s['players'][$pidx]['answers'][$qi]=array('choice'=>-1,'text'=>$tx,'ts'=>$now,'correct'=>$correct,'points'=>$pts);
          $done=(($qi+1)>=$total); if($done){ $s['players'][$pidx]['done']=true; $s['players'][$pidx]['finished']=$now; }
          $fb=array('correct'=>$correct,'correctIndex'=>-1,'points'=>$pts,'done'=>$done,'canonical'=>(isset($q['answers'][0]['text'])?(string)$q['answers'][0]['text']:''));
          return $s;
        }
        if($choice<0||$choice>=count($q['answers'])) return false;
        $correctIdx=-1; foreach($q['answers'] as $i=>$a){ if(!empty($a['correct'])){ $correctIdx=$i; break; } }
        $correct=($choice===$correctIdx); $pts=0;
        if($correct){ $pts=(int)$q['points']; $st0=(int)$s['players'][$pidx]['streak']; if($st0>=1) $pts+=min($st0,5)*100; $s['players'][$pidx]['streak']=$st0+1; if($s['players'][$pidx]['streak']>(int)$s['players'][$pidx]['best']) $s['players'][$pidx]['best']=$s['players'][$pidx]['streak']; $s['players'][$pidx]['correct']=(int)$s['players'][$pidx]['correct']+1; }
        else { $s['players'][$pidx]['streak']=0; }
        $now=(int)round(microtime(true)*1000); $s['players'][$pidx]['score']=(int)$s['players'][$pidx]['score']+$pts;
        $s['players'][$pidx]['answers'][$qi]=array('choice'=>$choice,'ts'=>$now,'correct'=>$correct,'points'=>$pts);
        $done=(($qi+1)>=$total); if($done){ $s['players'][$pidx]['done']=true; $s['players'][$pidx]['finished']=$now; }
        $fb=array('correct'=>$correct,'correctIndex'=>$correctIdx,'points'=>$pts,'done'=>$done);
        return $s;
      });
      if($st!=='ok') qff_err($st==='reject'?'not accepted':'not found', $st==='reject'?409:404);
      qff_json(array('ok'=>true,'correct'=>$fb['correct'],'correctIndex'=>$fb['correctIndex'],'points'=>$fb['points'],'done'=>$fb['done']));
    }
    list($s,$st)=qff_live_mutate($code,function($s) use($pid,$choice,$atext){
      if(($s['type']??'')!=='game' || ($s['phase']??'')!=='question') return false;
      $qi=(int)$s['qIndex']; if($qi<0) return false; $q=$s['quiz']['questions'][$qi];
      $pidx=-1; foreach($s['players'] as $i=>$p){ if($p['id']===$pid){ $pidx=$i; break; } } if($pidx<0) return false;
      if(isset($s['players'][$pidx]['answers'][$qi])) return false;
      if(($q['type']??'')==='type'){
        $now=(int)round(microtime(true)*1000); $qStart=(int)$s['qStart']; $timeLimit=(int)$q['time']; $elapsed=($now-$qStart)/1000.0; if($elapsed<0)$elapsed=0;
        $acc=array(); foreach($q['answers'] as $aa){ $acc[]=$aa['text']; } $tx=qff_clip($atext,90);
        if($elapsed > $timeLimit + 1.0){ $s['players'][$pidx]['answers'][$qi]=array('choice'=>-1,'text'=>$tx,'ts'=>$now,'correct'=>false,'points'=>0); $s['players'][$pidx]['streak']=0; return $s; }
        $correct=qff_text_match($tx,$acc); $pts=0;
        if($correct){ $base=(int)$q['points']; $frac=$timeLimit>0?min(1.0,$elapsed/$timeLimit):0; $pts=(int)round($base*(1-$frac/2)); $st0=(int)$s['players'][$pidx]['streak']; if($st0>=1) $pts+=min($st0,5)*100; $s['players'][$pidx]['streak']=$st0+1; if($s['players'][$pidx]['streak']>(int)$s['players'][$pidx]['best']) $s['players'][$pidx]['best']=$s['players'][$pidx]['streak']; $s['players'][$pidx]['correct']=(int)$s['players'][$pidx]['correct']+1; }
        else { $s['players'][$pidx]['streak']=0; }
        $s['players'][$pidx]['score']=(int)$s['players'][$pidx]['score']+$pts;
        $s['players'][$pidx]['answers'][$qi]=array('choice'=>-1,'text'=>$tx,'ts'=>$now,'correct'=>$correct,'points'=>$pts);
        return $s;
      }
      if(($q['type']??'')==='num'){
        $now=(int)round(microtime(true)*1000); $qStart=(int)$s['qStart']; $timeLimit=(int)$q['time']; $elapsed=($now-$qStart)/1000.0; if($elapsed<0)$elapsed=0; $tx=qff_clip($atext,90);
        if($elapsed > $timeLimit + 1.0){ $s['players'][$pidx]['answers'][$qi]=array('choice'=>-1,'text'=>$tx,'ts'=>$now,'correct'=>false,'points'=>0); $s['players'][$pidx]['streak']=0; return $s; }
        $r=qff_num_score($tx,(isset($q['answers'][0]['text'])?$q['answers'][0]['text']:''),isset($q['tol'])?$q['tol']:0); $correct=$r['ok']; $pts=0;
        if($correct){ $base=(int)$q['points']; $frac=$timeLimit>0?min(1.0,$elapsed/$timeLimit):0; $pts=(int)round($base*(1-$frac/2)*$r['close']); $st0=(int)$s['players'][$pidx]['streak']; if($st0>=1) $pts+=min($st0,5)*100; $s['players'][$pidx]['streak']=$st0+1; if($s['players'][$pidx]['streak']>(int)$s['players'][$pidx]['best']) $s['players'][$pidx]['best']=$s['players'][$pidx]['streak']; $s['players'][$pidx]['correct']=(int)$s['players'][$pidx]['correct']+1; }
        else { $s['players'][$pidx]['streak']=0; }
        $s['players'][$pidx]['score']=(int)$s['players'][$pidx]['score']+$pts;
        $s['players'][$pidx]['answers'][$qi]=array('choice'=>-1,'text'=>$tx,'ts'=>$now,'correct'=>$correct,'points'=>$pts);
        return $s;
      }
      if($choice<0||$choice>=count($q['answers'])) return false;
      $now=(int)round(microtime(true)*1000); $qStart=(int)$s['qStart']; $timeLimit=(int)$q['time'];
      $elapsed=($now-$qStart)/1000.0; if($elapsed<0)$elapsed=0;
      if($elapsed > $timeLimit + 1.0){ $s['players'][$pidx]['answers'][$qi]=array('choice'=>$choice,'ts'=>$now,'correct'=>false,'points'=>0); $s['players'][$pidx]['streak']=0; return $s; }
      $correctIdx=-1; foreach($q['answers'] as $i=>$a){ if(!empty($a['correct'])){ $correctIdx=$i; break; } }
      $correct=($choice===$correctIdx); $pts=0;
      if($correct){ $base=(int)$q['points']; $frac=$timeLimit>0?min(1.0,$elapsed/$timeLimit):0; $pts=(int)round($base*(1-$frac/2));
        $st0=(int)$s['players'][$pidx]['streak']; if($st0>=1) $pts+=min($st0,5)*100;
        $s['players'][$pidx]['streak']=$st0+1; if($s['players'][$pidx]['streak']>(int)$s['players'][$pidx]['best']) $s['players'][$pidx]['best']=$s['players'][$pidx]['streak'];
        $s['players'][$pidx]['correct']=(int)$s['players'][$pidx]['correct']+1;
      } else { $s['players'][$pidx]['streak']=0; }
      $s['players'][$pidx]['score']=(int)$s['players'][$pidx]['score']+$pts;
      $s['players'][$pidx]['answers'][$qi]=array('choice'=>$choice,'ts'=>$now,'correct'=>$correct,'points'=>$pts);
      return $s;
    });
    if($st!=='ok') qff_err($st==='reject'?'not accepted':'not found', $st==='reject'?409:404);
    qff_json(array('ok'=>true,'locked'=>true));
  }
  if($action==='live_react'){
    $b=qff_body();
    $code=qff_safe_code(isset($b['code'])?$b['code']:''); $e=(int)(isset($b['e'])?$b['e']:-1);
    if($code===''||$e<0||$e>5) qff_err('bad request');
    $rnow=microtime(true); $rl=isset($_SESSION['react_last'])?$_SESSION['react_last']:0; if(($rnow-$rl)<0.2) qff_err('slow down',429); $_SESSION['react_last']=$rnow;
    list($s,$st)=qff_live_mutate($code,function($s) use($e){
      if(($s['type']??'')!=='game') return false;
      $rx=isset($s['reactions'])?$s['reactions']:array();
      $rx[]=array('e'=>$e,'ts'=>(int)round(microtime(true)*1000));
      if(count($rx)>40) $rx=array_slice($rx,-40);
      $s['reactions']=$rx; return $s;
    });
    if($st!=='ok') qff_err('not found',404);
    qff_json(array('ok'=>true));
  }
  if($action==='live_report'){
    qff_require_admin();
    $code=qff_safe_code(isset($_GET['code'])?$_GET['code']:''); if($code==='') qff_err('bad code');
    $s=qff_live_load($code); if(!$s) qff_err('not found',404);
    if(!in_array(($s['type']??''),array('game','assign'),true)) qff_err('not a game');
    $quiz=$s['quiz']; $qs=isset($quiz['questions'])?$quiz['questions']:array();
    $qout=array();
    foreach($qs as $q){ $ci=-1; foreach($q['answers'] as $j=>$a){ if(!empty($a['correct'])){ $ci=$j; break; } }
      $ans=array(); foreach($q['answers'] as $a) $ans[]=array('text'=>$a['text']);
      $qout[]=array('text'=>$q['text'],'time'=>(int)$q['time'],'points'=>(int)$q['points'],'type'=>$q['type'],'correctIndex'=>$ci,'answers'=>$ans); }
    $players=array();
    foreach((isset($s['players'])?$s['players']:array()) as $p){
      $ans=array();
      foreach((isset($p['answers'])?$p['answers']:array()) as $qi=>$a){ $ans[(string)$qi]=array('choice'=>(int)$a['choice'],'correct'=>!empty($a['correct']),'points'=>(int)$a['points'],'ts'=>(int)$a['ts']); }
      $players[]=array('id'=>$p['id'],'name'=>$p['name'],'avatar'=>(int)(isset($p['avatar'])?$p['avatar']:0),'score'=>(int)$p['score'],'best'=>(int)(isset($p['best'])?$p['best']:0),'correct'=>(int)(isset($p['correct'])?$p['correct']:0),'answers'=>$ans);
    }
    $qStarts=array(); foreach((isset($s['qStarts'])&&is_array($s['qStarts'])?$s['qStarts']:array()) as $k=>$v) $qStarts[(string)$k]=(int)$v;
    qff_json(array('report'=>array('prompt'=>$s['prompt'],'code'=>$s['code'],'total'=>count($qs),'count'=>count(isset($s['players'])?$s['players']:array()),'questions'=>$qout,'players'=>$players,'qStarts'=>(object)$qStarts)));
  }
  if($action==='live_list'){
    qff_require_admin(); global $DATA; $list=array(); $files=glob($DATA.'/live/*.json'); if(!$files)$files=array();
    foreach($files as $f){ $s=json_decode((string)file_get_contents($f),true); if(!is_array($s)) continue; $cnt=0; foreach((isset($s['entries'])?$s['entries']:array()) as $e) if(empty($e['hidden'])) $cnt++;
      $list[]=array('code'=>$s['code'],'type'=>$s['type'],'prompt'=>$s['prompt'],'open'=>!empty($s['open']),'count'=>$cnt,'created'=>isset($s['created'])?$s['created']:0); }
    usort($list,function($a,$b){ return $b['created']-$a['created']; });
    qff_json(array('sessions'=>array_slice($list,0,50)));
  }

  qff_err('unknown action',404);
}

/* ---- normal page render: expose minimal config to the client ---- */
$cfg=qff_cfg();
$QFF_CLIENT=array(
  'server'      => true,
  'admin'       => qff_is_admin(),
  'adminExists' => ((isset($cfg['admin_hash'])?$cfg['admin_hash']:'')!==''),
  'moderate'    => !empty($cfg['moderate']),
  'csrf'        => qff_csrf(),
);
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark">
<meta name="description" content="Quiz fara frontiere — joc de tip Kahoot, single-file, offline, fara conturi, fara reclame, fara telemetrie.">
<title>Undava</title>
<meta name="theme-color" content="#16092e">
<link rel="manifest" href="?asset=manifest">
<link rel="icon" type="image/svg+xml" href="?asset=icon">
<link rel="apple-touch-icon" href="?asset=icon">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Quiz">
<!--
  Undava — un joc de quiz în spiritul Kahoot.
  Single-file • offline-first • vanilla JS • zero telemetrie • zero conturi • trade-free.
  Datele tale (quiz-urile create) rămân pe dispozitivul tău. Export/import prin fișiere JSON.
-->
<style>
:root{
  --ink:#16092e; --ink2:#241046; --ink3:#2f1659;
  --paper:#fdf7ff; --paper2:#f1e8fb;
  --text:#ffffff; --muted:#b7a8da; --muted2:#8c7bb6;
  --line:rgba(255,255,255,.12);
  --accent:#ffd23f;   /* gold marquee */
  --accent2:#ff4e8a;  /* hot pink */
  --teal:#2ee6c4;     /* electric mint */
  --c1:#e8385a; --c2:#2f6bff; --c3:#f7a823; --c4:#18bd6b; /* answer colors */
  --good:#18bd6b; --bad:#e8385a;
  --r:20px; --r-sm:12px;
  --shadow:0 18px 50px rgba(0,0,0,.45);
  --shadow-sm:0 8px 22px rgba(0,0,0,.30);
  --press:0 6px 0;
  --maxw:1100px;
  --font: ui-rounded, "SF Pro Rounded", "Segoe UI", system-ui, -apple-system, Roboto, sans-serif;
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0}
body{
  font-family:var(--font);
  color:var(--text);
  background:var(--ink);
  min-height:100dvh;
  overflow-x:hidden;
  -webkit-font-smoothing:antialiased;
}
/* ambient stage background */
.bg{position:fixed;inset:0;z-index:-2;background:
   radial-gradient(120% 90% at 12% 0%, #3a1466 0%, transparent 55%),
   radial-gradient(120% 90% at 95% 8%, #6a1a5c 0%, transparent 50%),
   radial-gradient(140% 120% at 50% 120%, #1c0b46 0%, transparent 60%),
   var(--ink);}
.bg b{position:fixed;border-radius:50%;filter:blur(60px);opacity:.45;z-index:-1;animation:float 22s ease-in-out infinite}
.bg b:nth-child(1){width:340px;height:340px;background:#7b2ff7;left:-90px;top:8%}
.bg b:nth-child(2){width:300px;height:300px;background:#ff4e8a;right:-80px;top:30%;animation-delay:-6s}
.bg b:nth-child(3){width:260px;height:260px;background:#2ee6c4;left:30%;bottom:-90px;animation-delay:-12s;opacity:.30}
@keyframes float{0%,100%{transform:translate(0,0)}50%{transform:translate(28px,-34px)}}

button{font-family:inherit;cursor:pointer;border:none;color:inherit}
input,textarea,select{font-family:inherit}
.hidden{display:none!important}

/* ---------- top bar ---------- */
.topbar{position:sticky;top:0;z-index:40;display:flex;align-items:center;gap:12px;
  padding:14px clamp(14px,4vw,28px);max-width:var(--maxw);margin:0 auto;width:100%}
.brand{display:flex;align-items:center;gap:11px;cursor:pointer;user-select:none;margin-right:auto}
.brand .logo{width:42px;height:42px;border-radius:13px;display:grid;place-items:center;
  background:conic-gradient(from 200deg,var(--c1),var(--c3),var(--c4),var(--c2),var(--c1));
  box-shadow:0 0 0 3px rgba(255,255,255,.08), var(--shadow-sm);position:relative;flex:none}
.brand .logo::after{content:"?";font-weight:900;font-size:24px;color:#fff;text-shadow:0 2px 5px rgba(0,0,0,.4)}
.brand h1{font-size:18px;margin:0;letter-spacing:.2px;line-height:1}
.brand small{display:block;font-size:10.5px;font-weight:700;letter-spacing:2px;color:var(--accent);text-transform:uppercase;margin-top:3px}
.tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.iconbtn{width:42px;height:42px;border-radius:12px;background:var(--surface,rgba(255,255,255,.08));
  display:grid;place-items:center;font-size:18px;color:#fff;border:1px solid var(--line);transition:.15s}
.iconbtn:hover{background:rgba(255,255,255,.16);transform:translateY(-1px)}
.langtog{display:flex;flex-wrap:wrap;border:1px solid var(--line);border-radius:12px;overflow:hidden}
.langtog button{padding:9px 11px;background:transparent;font-weight:800;font-size:13px;color:var(--muted);line-height:1}
.langtog button.on{background:var(--accent);color:#2a1000}

/* ---------- layout ---------- */
.wrap{max-width:var(--maxw);margin:0 auto;padding:8px clamp(14px,4vw,28px) 60px;width:100%}
.center-stage{min-height:calc(100dvh - 80px);display:flex;flex-direction:column;justify-content:center}

/* ---------- home ---------- */
.hero{text-align:center;padding:18px 0 30px}
.hero .kicker{display:inline-block;font-size:12px;font-weight:800;letter-spacing:3px;text-transform:uppercase;
  color:var(--ink);background:var(--accent);padding:6px 14px;border-radius:999px;margin-bottom:22px}
.hero h2{font-size:clamp(38px,8vw,76px);line-height:.96;margin:0 0 16px;font-weight:900;letter-spacing:-1.5px}
.hero h2 .pop{background:linear-gradient(90deg,var(--accent2),var(--accent));-webkit-background-clip:text;background-clip:text;color:transparent}
.hero p{font-size:clamp(15px,2.4vw,19px);color:var(--muted);max-width:540px;margin:0 auto 30px;line-height:1.5}
.cta-row{display:flex;gap:14px;flex-wrap:wrap;justify-content:center}
.btn{padding:16px 28px;border-radius:16px;font-weight:800;font-size:16px;display:inline-flex;align-items:center;gap:10px;
  transition:transform .12s ease, box-shadow .12s ease, filter .12s;line-height:1}
.btn:active{transform:translateY(3px)}
.btn-primary{background:var(--accent);color:#2a1000;box-shadow:0 6px 0 #c79a17}
.btn-primary:active{box-shadow:0 3px 0 #c79a17}
.btn-pink{background:var(--accent2);color:#fff;box-shadow:0 6px 0 #c12d63}
.btn-pink:active{box-shadow:0 3px 0 #c12d63}
.btn-ghost{background:rgba(255,255,255,.08);color:#fff;border:1px solid var(--line);box-shadow:none}
.btn-ghost:active{transform:translateY(2px)}
.btn-teal{background:var(--teal);color:#04332b;box-shadow:0 6px 0 #1fae93}
.btn-teal:active{box-shadow:0 3px 0 #1fae93}
.btn-lg{padding:20px 34px;font-size:19px;border-radius:18px}
.btn-block{width:100%;justify-content:center}
.btn-danger{background:rgba(232,56,90,.16);color:#ff90a8;border:1px solid rgba(232,56,90,.4);box-shadow:none}
.btn[disabled]{opacity:.45;pointer-events:none}
.feat{display:flex;gap:18px;flex-wrap:wrap;justify-content:center;margin-top:42px;color:var(--muted2);font-size:13px;font-weight:600}
.feat span{display:inline-flex;align-items:center;gap:6px}
.feat b{color:var(--teal)}

/* ---------- section heading ---------- */
.page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:6px 0 22px;flex-wrap:wrap}
.page-head h2{font-size:clamp(26px,5vw,40px);margin:0;font-weight:900;letter-spacing:-.5px}
.page-head .sub{color:var(--muted);font-size:14px;margin-top:4px}

/* ---------- cards / library ---------- */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:16px}
.qcard{background:var(--surface,rgba(255,255,255,.06));border:1px solid var(--line);border-radius:var(--r);
  padding:0;overflow:hidden;display:flex;flex-direction:column;transition:transform .15s, box-shadow .15s}
.qcard:hover{transform:translateY(-4px);box-shadow:var(--shadow)}
.qcard .top{height:96px;display:flex;align-items:flex-end;padding:14px;position:relative;color:#fff}
.qcard .top .badge{position:absolute;top:12px;right:12px;background:rgba(0,0,0,.32);backdrop-filter:blur(6px);
  font-size:11px;font-weight:800;letter-spacing:.5px;padding:5px 10px;border-radius:999px}
.qcard .top .qn{font-weight:900;font-size:34px;opacity:.9;text-shadow:0 3px 8px rgba(0,0,0,.3)}
.qcard .body{padding:15px 16px 16px;display:flex;flex-direction:column;gap:6px;flex:1}
.qcard h3{margin:0;font-size:18px;font-weight:800;line-height:1.2}
.qcard .desc{color:var(--muted);font-size:13px;line-height:1.45;flex:1}
.qcard .meta{color:var(--muted2);font-size:12px;font-weight:700;display:flex;gap:12px;margin-top:4px}
.qcard .actions{display:flex;gap:8px;padding:0 16px 16px}
.qcard .actions .btn{padding:11px 14px;font-size:14px;border-radius:12px;flex:1}
.mini{width:42px;height:42px;flex:none!important}
.card-empty{grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--muted);border:1.5px dashed var(--line);border-radius:var(--r)}

/* ---------- editor ---------- */
.panel{background:rgba(255,255,255,.05);border:1px solid var(--line);border-radius:var(--r);padding:20px;margin-bottom:18px}
.field{margin-bottom:16px}
.field label{display:block;font-size:13px;font-weight:800;color:var(--muted);margin-bottom:7px;letter-spacing:.3px}
.input,.textarea,.select{width:100%;background:rgba(0,0,0,.25);border:1.5px solid var(--line);border-radius:var(--r-sm);
  padding:13px 15px;color:#fff;font-size:16px;font-weight:600;transition:.15s}
.input:focus,.textarea:focus,.select:focus{outline:none;border-color:var(--accent);background:rgba(0,0,0,.4)}
.textarea{resize:vertical;min-height:60px;line-height:1.4}
.input::placeholder,.textarea::placeholder{color:var(--muted2);font-weight:500}
.select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23b7a8da' stroke-width='3'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;padding-right:40px}
.row{display:flex;gap:12px;flex-wrap:wrap}
.row>*{flex:1;min-width:130px}

.qedit{border:1.5px solid var(--line);border-radius:var(--r);margin-bottom:14px;overflow:hidden;background:rgba(0,0,0,.16)}
.qedit .qhead{display:flex;align-items:center;gap:10px;padding:12px 14px;background:rgba(255,255,255,.05);flex-wrap:wrap}
.qedit .qnum{font-weight:900;font-size:15px;color:var(--accent);white-space:nowrap}
.qedit .qhead .spacer{flex:1}
.qedit .qbody{padding:14px}
.ans-rows{display:grid;gap:10px}
.ans-row{display:flex;align-items:center;gap:10px}
.ans-row .shape{width:38px;height:38px;border-radius:10px;flex:none;display:grid;place-items:center}
.ans-row .shape svg{width:20px;height:20px;fill:#fff}
.ans-row .input{flex:1}
.ans-row .corr{display:flex;align-items:center;gap:6px;flex:none}
.ans-row .corr input{width:22px;height:22px;accent-color:var(--good)}
.ans-row .corr label{font-size:12px;font-weight:800;color:var(--muted);margin:0;cursor:pointer}
.ans-row .rm{width:36px;height:36px;border-radius:9px;background:rgba(232,56,90,.14);color:#ff90a8;font-size:18px;flex:none;border:1px solid rgba(232,56,90,.3)}
.sc1{background:var(--c1)} .sc2{background:var(--c2)} .sc3{background:var(--c3)} .sc4{background:var(--c4)}
.editor-actions{display:flex;gap:12px;flex-wrap:wrap;position:sticky;bottom:0;background:linear-gradient(transparent,var(--ink) 40%);padding:16px 0 8px}

/* ---------- setup ---------- */
.mode-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px}
.mode-card{border:2px solid var(--line);border-radius:var(--r);padding:22px;cursor:pointer;text-align:center;transition:.15s;background:rgba(255,255,255,.04)}
.mode-card:hover{border-color:var(--muted);transform:translateY(-2px)}
.mode-card.on{border-color:var(--accent);background:rgba(255,210,63,.10);box-shadow:0 0 0 4px rgba(255,210,63,.12)}
.mode-card .ico{font-size:40px;margin-bottom:8px}
.mode-card h3{margin:0 0 6px;font-size:19px}
.mode-card p{margin:0;color:var(--muted);font-size:13px;line-height:1.4}
.players-list{display:grid;gap:10px;margin-bottom:14px}
.player-row{display:flex;gap:10px;align-items:center}
.player-row .av{width:42px;height:42px;border-radius:50%;flex:none;display:grid;place-items:center;font-weight:900;font-size:17px;color:#16092e}
.player-row .input{flex:1}

/* ---------- play stage ---------- */
.stage{min-height:calc(100dvh - 0px);display:flex;flex-direction:column;position:fixed;inset:0;z-index:30;padding:0}
.stage-strip{display:flex;align-items:center;gap:14px;padding:14px clamp(14px,4vw,28px);max-width:var(--maxw);margin:0 auto;width:100%}
.pill{background:rgba(0,0,0,.3);border:1px solid var(--line);border-radius:999px;padding:9px 16px;font-weight:800;font-size:14px;display:flex;align-items:center;gap:8px}
.pill.score{margin-left:auto;background:var(--accent);color:#2a1000;border:none}
.stage-main{flex:1;display:flex;flex-direction:column;max-width:var(--maxw);margin:0 auto;width:100%;padding:0 clamp(14px,4vw,28px) clamp(14px,3vw,24px)}

/* countdown */
.countdown{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:14px}
.countdown .qof{font-size:15px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:var(--accent)}
.countdown .big{font-size:clamp(90px,28vw,200px);font-weight:900;line-height:1;
  background:linear-gradient(180deg,#fff,var(--accent));-webkit-background-clip:text;background-clip:text;color:transparent;
  animation:pulse .9s ease}
.countdown .qtext{font-size:clamp(20px,4vw,34px);font-weight:800;max-width:760px;line-height:1.2;margin-top:4px}
@keyframes pulse{0%{transform:scale(.4);opacity:0}40%{transform:scale(1.08);opacity:1}100%{transform:scale(1)}}

/* question */
.q-area{flex:1;display:flex;flex-direction:column;gap:14px;padding-top:6px}
.q-card{background:var(--paper);color:#2a1144;border-radius:var(--r);padding:clamp(18px,3.5vw,34px);text-align:center;
  font-weight:800;font-size:clamp(20px,3.6vw,34px);line-height:1.25;box-shadow:var(--shadow);
  display:flex;align-items:center;justify-content:center;min-height:clamp(110px,20vh,200px)}
.timer-wrap{display:flex;align-items:center;gap:16px}
.ring{width:74px;height:74px;flex:none;border-radius:50%;display:grid;place-items:center;position:relative;
  background:conic-gradient(var(--accent) var(--deg,360deg), rgba(255,255,255,.12) 0);transition:background .1s linear}
.ring::before{content:"";position:absolute;inset:7px;border-radius:50%;background:var(--ink2)}
.ring span{position:relative;font-weight:900;font-size:26px;z-index:1}
.ring.warn{background:conic-gradient(var(--bad) var(--deg,360deg), rgba(255,255,255,.12) 0)}
.timer-bar{flex:1;height:14px;border-radius:999px;background:rgba(255,255,255,.12);overflow:hidden}
.timer-bar i{display:block;height:100%;width:100%;border-radius:999px;background:linear-gradient(90deg,var(--teal),var(--accent));transition:width .1s linear}
.timer-bar.warn i{background:var(--bad)}
.hint-row{text-align:center;color:var(--muted);font-size:13px;font-weight:600;min-height:18px}

.answers{display:grid;grid-template-columns:1fr 1fr;gap:clamp(10px,1.6vw,16px);margin-top:auto}
.answers.two{grid-template-columns:1fr 1fr}
.ans{border:none;border-radius:var(--r);padding:clamp(16px,2.6vw,26px);min-height:clamp(78px,13vh,120px);
  display:flex;align-items:center;gap:14px;font-weight:800;font-size:clamp(16px,2.4vw,22px);color:#fff;text-align:left;
  position:relative;transition:transform .1s, filter .15s, opacity .2s;box-shadow:0 7px 0 rgba(0,0,0,.28)}
.ans:not([disabled]):hover{transform:translateY(-2px);filter:brightness(1.06)}
.ans:not([disabled]):active{transform:translateY(4px);box-shadow:0 3px 0 rgba(0,0,0,.28)}
.ans .ico{width:clamp(34px,5vw,46px);height:clamp(34px,5vw,46px);flex:none;display:grid;place-items:center}
.ans .ico svg{width:60%;height:60%;fill:#fff;filter:drop-shadow(0 2px 3px rgba(0,0,0,.3))}
.a1{background:var(--c1)} .a2{background:var(--c2)} .a3{background:var(--c3)} .a4{background:var(--c4)}
.ans[disabled]{cursor:default}
.ans.dim{opacity:.32;filter:saturate(.6)}
.ans.correct{box-shadow:0 0 0 4px #fff, 0 7px 0 rgba(0,0,0,.28);animation:bump .4s}
.ans.wrong{opacity:.5}
.ans .mark{position:absolute;top:10px;right:12px;font-size:22px;font-weight:900;opacity:0}
.ans.correct .mark,.ans.wrong .mark{opacity:1}
@keyframes bump{0%,100%{transform:translateY(0)}30%{transform:translateY(-6px) scale(1.02)}}
.picked-tag{position:absolute;bottom:8px;right:12px;font-size:11px;font-weight:800;background:rgba(0,0,0,.35);padding:3px 9px;border-radius:999px}

/* pass screen (hotseat) */
.pass{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:18px}
.pass .av-big{width:110px;height:110px;border-radius:50%;display:grid;place-items:center;font-weight:900;font-size:46px;color:#16092e;box-shadow:var(--shadow)}
.pass h2{font-size:clamp(24px,5vw,38px);margin:0}
.pass p{color:var(--muted);margin:0;font-size:16px}

/* reveal */
.reveal{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:10px;padding:10px 0}
.reveal .verdict{font-size:clamp(34px,8vw,68px);font-weight:900;line-height:1;animation:pop .4s}
.reveal .verdict.ok{color:var(--good)} .reveal .verdict.no{color:var(--bad)}
@keyframes pop{0%{transform:scale(.5);opacity:0}50%{transform:scale(1.12)}100%{transform:scale(1)}}
.reveal .points{font-size:clamp(28px,6vw,46px);font-weight:900;color:var(--accent)}
.reveal .correct-was{color:var(--muted);font-size:15px}
.reveal .correct-was b{color:#fff}
.reveal .streak{display:inline-flex;align-items:center;gap:7px;background:rgba(255,78,138,.16);color:#ff9cc0;
  padding:8px 16px;border-radius:999px;font-weight:800;font-size:14px;border:1px solid rgba(255,78,138,.3)}
.reveal-results{display:grid;gap:8px;width:100%;max-width:440px;margin-top:6px}
.rr{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.06);border-radius:12px;padding:10px 14px}
.rr .av{width:32px;height:32px;border-radius:50%;flex:none;display:grid;place-items:center;font-weight:900;font-size:13px;color:#16092e}
.rr .nm{flex:1;text-align:left;font-weight:800}
.rr .pt{font-weight:900;color:var(--accent)}
.rr.miss .pt{color:var(--muted2)}

/* scoreboard */
.scoreboard{flex:1;display:flex;flex-direction:column;justify-content:center;gap:10px;max-width:600px;margin:0 auto;width:100%}
.sb-row{display:flex;align-items:center;gap:14px;background:rgba(255,255,255,.07);border-radius:14px;padding:14px 18px;
  animation:slidein .4s both}
.sb-row:nth-child(1){background:linear-gradient(90deg,rgba(255,210,63,.25),rgba(255,255,255,.07));border:1px solid rgba(255,210,63,.4)}
.sb-row .rk{font-weight:900;font-size:20px;width:30px;color:var(--accent)}
.sb-row .av{width:40px;height:40px;border-radius:50%;flex:none;display:grid;place-items:center;font-weight:900;color:#16092e}
.sb-row .nm{flex:1;font-weight:800;font-size:17px}
.sb-row .mv{font-size:13px;font-weight:800;width:46px;text-align:right}
.sb-row .mv.up{color:var(--good)} .sb-row .mv.down{color:var(--bad)} .sb-row .mv.same{color:var(--muted2)}
.sb-row .sc{font-weight:900;font-size:19px;width:78px;text-align:right}
@keyframes slidein{from{opacity:0;transform:translateX(-24px)}to{opacity:1;transform:none}}

/* podium */
.podium-screen{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;padding:20px 0}
.podium-screen h2{font-size:clamp(28px,6vw,46px);margin:0;text-align:center}
.podium{display:flex;align-items:flex-end;justify-content:center;gap:clamp(8px,2vw,20px);width:100%;max-width:560px}
.pod{display:flex;flex-direction:column;align-items:center;gap:10px;flex:1;max-width:160px;animation:rise .6s both}
.pod .av{width:clamp(54px,12vw,76px);height:clamp(54px,12vw,76px);border-radius:50%;display:grid;place-items:center;font-weight:900;font-size:clamp(22px,5vw,32px);color:#16092e;box-shadow:var(--shadow-sm)}
.pod .nm{font-weight:800;font-size:15px;text-align:center;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pod .sc{font-weight:900;color:var(--accent);font-size:15px}
.pod .bar{width:100%;border-radius:14px 14px 0 0;display:flex;align-items:flex-start;justify-content:center;padding-top:12px;
  font-size:34px;font-weight:900;color:rgba(0,0,0,.5)}
.pod.p1 .bar{height:150px;background:linear-gradient(180deg,#ffd23f,#e0a800)}
.pod.p2 .bar{height:110px;background:linear-gradient(180deg,#dfe6ef,#9aa7b8)}
.pod.p3 .bar{height:84px;background:linear-gradient(180deg,#f0a96b,#c97b3c)}
.pod.p1{order:2} .pod.p2{order:1} .pod.p3{order:3}
@keyframes rise{from{opacity:0;transform:translateY(40px)}to{opacity:1;transform:none}}
.rest-list{width:100%;max-width:460px;display:grid;gap:8px}
.solo-stats{display:flex;gap:14px;flex-wrap:wrap;justify-content:center}
.stat{background:rgba(255,255,255,.07);border:1px solid var(--line);border-radius:16px;padding:16px 22px;text-align:center;min-width:120px}
.stat .v{font-size:32px;font-weight:900;color:var(--accent)}
.stat .l{font-size:12px;font-weight:700;color:var(--muted);letter-spacing:.5px;text-transform:uppercase;margin-top:2px}

/* confetti */
#confetti{position:fixed;inset:0;pointer-events:none;z-index:31}

/* settings modal */
.modal-bg{position:fixed;inset:0;z-index:60;background:rgba(8,3,20,.7);backdrop-filter:blur(6px);display:grid;place-items:center;padding:20px;animation:fade .2s}
@keyframes fade{from{opacity:0}to{opacity:1}}
.modal{background:var(--ink2);border:1px solid var(--line);border-radius:var(--r);padding:24px;max-width:440px;width:100%;box-shadow:var(--shadow)}
.modal h3{margin:0 0 18px;font-size:22px}
.set-row{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 0;border-bottom:1px solid var(--line)}
.set-row:last-of-type{border:none}
.set-row .t{font-weight:700}.set-row .d{font-size:12px;color:var(--muted);margin-top:2px}
.switch{width:54px;height:30px;border-radius:999px;background:rgba(255,255,255,.18);position:relative;transition:.2s;flex:none}
.switch.on{background:var(--good)}
.switch::after{content:"";position:absolute;top:3px;left:3px;width:24px;height:24px;border-radius:50%;background:#fff;transition:.2s}
.switch.on::after{left:27px}
.about{font-size:13px;color:var(--muted);line-height:1.6;margin-top:16px}
.about b{color:var(--teal)}

/* import */
.dropzone{border:2px dashed var(--line);border-radius:var(--r);padding:40px 20px;text-align:center;color:var(--muted);transition:.15s;cursor:pointer}
.dropzone.over{border-color:var(--accent);background:rgba(255,210,63,.08);color:#fff}
.dropzone .ico{font-size:44px;margin-bottom:10px}

.toast{position:fixed;left:50%;bottom:30px;transform:translateX(-50%);background:#fff;color:#1a0b33;font-weight:800;
  padding:13px 22px;border-radius:14px;box-shadow:var(--shadow);z-index:80;animation:toastin .3s;max-width:90vw;text-align:center}
@keyframes toastin{from{opacity:0;transform:translate(-50%,18px)}to{opacity:1;transform:translate(-50%,0)}}

.backlink{display:inline-flex;align-items:center;gap:7px;color:var(--muted);font-weight:700;font-size:14px;
  background:rgba(255,255,255,.06);border:1px solid var(--line);padding:9px 15px;border-radius:11px;margin-bottom:18px;transition:.15s}
.backlink:hover{color:#fff;background:rgba(255,255,255,.12)}

@media(max-width:560px){
  .mode-grid{grid-template-columns:1fr}
  .qcard .actions{flex-wrap:wrap}
  .stage-strip{gap:8px}
  .pill{padding:8px 12px;font-size:13px}
}
@media(prefers-reduced-motion:reduce){
  *{animation-duration:.01ms!important;transition-duration:.06s!important}
  .bg b{animation:none}
}

/* ---------- feedback / guestbook ---------- */
.fb-form{position:relative}
.stars{display:flex;gap:8px}
.stars .star{width:46px;height:46px;border-radius:12px;border:1.5px solid var(--line);background:rgba(0,0,0,.25);color:#6b5b94;font-size:24px;line-height:1;cursor:pointer;transition:transform .12s,background .12s,color .12s}
.stars .star:hover{transform:translateY(-2px)}
.stars .star.on{color:var(--accent);border-color:rgba(255,210,63,.55);background:rgba(255,210,63,.12)}
.gb-list{display:grid;gap:12px}
.gb-card{background:rgba(255,255,255,.05);border:1px solid var(--line);border-radius:var(--r);padding:16px 18px}
.gb-card.pend{border-color:rgba(255,210,63,.45);background:rgba(255,210,63,.06)}
.gb-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.gb-who{font-weight:800;font-size:15px}
.gb-pend{font-size:11px;font-weight:800;color:#2a1000;background:var(--accent);padding:2px 8px;border-radius:999px;text-transform:uppercase;letter-spacing:.4px}
.gb-stars{color:var(--accent);font-size:17px;letter-spacing:2px;white-space:nowrap}
.gb-quiz{font-size:12px;font-weight:700;color:var(--muted);margin-top:4px}
.gb-msg{margin-top:8px;color:#efe9fb;line-height:1.5;white-space:pre-wrap;overflow-wrap:anywhere}
.gb-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:12px}
.gb-when{font-size:12px;color:var(--muted2)}
.gb-act{display:flex;gap:6px}
.set-row .t{font-weight:800;font-size:15px}
.set-row .d{font-size:12.5px;color:var(--muted);margin-top:2px;max-width:46ch}

/* ---------- live audience mode ---------- */
.ltype-head,.ltype-grid+.panel{margin-top:6px}
.ltype-head{font-weight:800;font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin:22px 0 10px}
.ltype-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}
.ltype{display:flex;flex-direction:column;align-items:flex-start;gap:4px;text-align:left;padding:16px;border-radius:var(--r);border:1.5px solid var(--line);background:rgba(255,255,255,.04);cursor:pointer;transition:transform .12s,border-color .12s,background .12s;color:inherit}
.ltype:hover{transform:translateY(-2px)}
.ltype.on{border-color:var(--accent);background:rgba(255,210,63,.10);box-shadow:0 0 0 1px rgba(255,210,63,.35) inset}
.lt-ic{font-size:24px}
.lt-nm{font-weight:800;font-size:15px}
.lt-d{font-size:12px;color:var(--muted)}
.opt-row{display:flex;gap:8px;align-items:center;margin-bottom:8px}
.opt-row .input{flex:1}
.big-input{font-size:18px;padding:14px 16px}
/* host */
.live-host{max-width:1180px;margin:0 auto;padding:18px}
.lh-bar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px}
.lh-meta{display:flex;align-items:center;gap:10px}
.lh-type{font-weight:800}
.lh-status{font-size:11px;font-weight:800;padding:3px 10px;border-radius:999px;letter-spacing:.5px}
.lh-status.open{background:rgba(24,189,107,.18);color:#37e08a;border:1px solid rgba(24,189,107,.4)}
.lh-status.closed{background:rgba(255,90,95,.16);color:#ff8488;border:1px solid rgba(255,90,95,.4)}
.lh-ctrls{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto}
.btn.sm{padding:8px 12px;font-size:13px;width:auto}
.btn.sm.danger{color:#ff8488;border-color:rgba(255,90,95,.4)}
.lh-main{display:grid;grid-template-columns:1fr 290px;gap:18px;align-items:start}
.lh-stage{background:rgba(0,0,0,.22);border:1px solid var(--line);border-radius:var(--r);padding:22px;min-height:60vh;display:flex;flex-direction:column}
.lh-prompt{font-size:26px;margin:0 0 16px;line-height:1.2}
.live-viz{position:relative;flex:1;min-height:46vh;overflow:hidden}
.live-viz.qa{overflow:auto;display:block}
.lh-foot{margin-top:14px;color:var(--muted)}
.lh-count b{color:var(--text);font-size:18px}
.cloud-word{position:absolute;font-weight:800;line-height:1;white-space:nowrap;transform:translate(-50%,-50%);transition:left .55s cubic-bezier(.22,1,.36,1),top .55s cubic-bezier(.22,1,.36,1),font-size .55s cubic-bezier(.22,1,.36,1),opacity .4s;will-change:left,top,font-size;text-shadow:0 1px 14px rgba(0,0,0,.25)}
.live-empty{display:flex;align-items:center;justify-content:center;height:100%;min-height:200px;color:var(--muted2);font-size:15px}
.lh-join{position:sticky;top:14px}
.join-card{background:linear-gradient(160deg,rgba(123,47,247,.18),rgba(47,107,255,.10));border:1px solid var(--line);border-radius:var(--r);padding:18px;text-align:center}
.jc-label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;font-weight:800}
.jc-host{font-size:13px;color:var(--text);opacity:.85;margin:4px 0 8px;word-break:break-all}
.jc-code{font-size:38px;font-weight:900;letter-spacing:6px;color:var(--accent);margin-bottom:14px}
.jc-qr{display:flex;justify-content:center;background:#fff;padding:10px;border-radius:14px;margin-bottom:12px}
.jc-actions{display:flex;gap:8px;justify-content:center;flex-wrap:wrap}
/* poll */
.poll-bars{display:flex;flex-direction:column;gap:14px;justify-content:center;height:100%}
.poll-row{}
.poll-top{display:flex;justify-content:space-between;gap:10px;margin-bottom:6px;font-weight:700}
.poll-label{font-size:16px}
.poll-num{font-size:14px;color:var(--muted);white-space:nowrap}
.poll-track{height:30px;border-radius:10px;background:rgba(255,255,255,.07);overflow:hidden}
.poll-track i{display:block;height:100%;width:0;border-radius:10px;transition:width .6s cubic-bezier(.22,1,.36,1)}
/* qa */
.qa-card{display:flex;gap:12px;align-items:flex-start;background:rgba(255,255,255,.05);border:1px solid var(--line);border-radius:14px;padding:12px 14px;margin-bottom:10px}
.qa-card.is-hidden{opacity:.45}
.qa-up{display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:46px;padding:6px 4px;border-radius:10px;border:1.5px solid var(--line);background:rgba(0,0,0,.2);color:var(--muted);font-size:12px;cursor:pointer;transition:transform .12s,color .12s,border-color .12s}
.qa-up b{font-size:15px;color:var(--text);font-weight:800}
.qa-up:not(:disabled):hover{transform:translateY(-2px)}
.qa-up.on{color:var(--accent);border-color:rgba(255,210,63,.5);background:rgba(255,210,63,.12)}
.qa-up:disabled{cursor:default}
.qa-body{flex:1}
.qa-text{line-height:1.4;overflow-wrap:anywhere}
.qa-name{font-size:12px;color:var(--muted);margin-top:4px}
.qa-admin{display:flex;gap:4px}
/* participant */
.join-view{max-width:620px;margin:0 auto;padding:18px}
.join-loading,.join-err{text-align:center;padding:60px 20px;color:var(--muted);font-size:16px}
.jv-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.jv-code{font-weight:800;letter-spacing:3px;color:var(--accent)}
.jv-prompt{font-size:23px;margin:0 0 16px;line-height:1.25}
.jv-closed{background:rgba(255,90,95,.12);border:1px solid rgba(255,90,95,.35);color:#ff9a9a;padding:10px 14px;border-radius:12px;margin-bottom:14px;font-weight:700}
.jv-input{position:relative;display:flex;flex-direction:column;gap:10px}
.jv-input .input,.jv-input .textarea{width:100%}
.join-hint{font-size:12px;color:var(--muted);text-align:center}
.join-done{text-align:center;font-weight:800;color:#37e08a;background:rgba(24,189,107,.12);border:1px solid rgba(24,189,107,.35);padding:14px;border-radius:12px}
.poll-choices{display:flex;flex-direction:column;gap:10px}
.poll-choice{padding:16px;border-radius:14px;border:1.5px solid var(--line);background:rgba(255,255,255,.05);font-size:16px;font-weight:700;cursor:pointer;transition:transform .12s,border-color .12s,background .12s;color:inherit;text-align:left}
.poll-choice:hover{transform:translateY(-2px);border-color:var(--accent);background:rgba(255,210,63,.08)}
.jv-results{margin-top:26px}
.jv-rlabel{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;font-weight:800;margin-bottom:10px}
.live-viz.join{min-height:240px;background:rgba(0,0,0,.18);border:1px solid var(--line);border-radius:var(--r);padding:14px}
.live-viz.join.qa{min-height:120px}
@media(max-width:820px){
  .lh-main{grid-template-columns:1fr}
  .lh-join{position:static}
  .ltype-grid{grid-template-columns:1fr}
  .lh-prompt{font-size:22px}
  .jc-code{font-size:32px}
}

/* ---------- live multiplayer game ---------- */
.live-mode-note{background:linear-gradient(160deg,rgba(123,47,247,.16),rgba(47,107,255,.08));border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;font-weight:700;color:var(--text);opacity:.9}
.game-host{max-width:1180px;margin:0 auto;padding:18px}
.gl-main{display:grid;grid-template-columns:300px 1fr;gap:18px;align-items:start;margin:8px 0 18px}
.gl-join{text-align:center}
.gl-players{background:rgba(0,0,0,.18);border:1px solid var(--line);border-radius:var(--r);padding:18px;min-height:50vh}
.gl-ptitle{font-weight:800;font-size:15px;margin-bottom:14px}
.gl-ptitle b{color:var(--accent);font-size:20px}
.g-chips{display:flex;flex-wrap:wrap;gap:10px;align-content:flex-start}
.g-chip{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.07);border:1px solid var(--line);border-radius:999px;padding:8px 12px 8px 10px;font-weight:700;animation:pop .3s both}
.g-chip-av{font-size:18px}
.g-kick{margin-left:2px;border:none;background:transparent;color:var(--muted2);cursor:pointer;font-size:12px;opacity:.5;padding:0 2px}
.g-chip:hover .g-kick{opacity:1;color:#ff8488}
.g-lobby-empty{color:var(--muted2);font-size:14px;padding:20px 0}
.gl-foot{display:flex;justify-content:center}
@keyframes pop{0%{transform:scale(.6);opacity:0}100%{transform:scale(1);opacity:1}}
.gq-stage{background:rgba(0,0,0,.22);border:1px solid var(--line);border-radius:var(--r);padding:24px;display:flex;flex-direction:column;align-items:center;min-height:64vh}
.gq-text{font-size:clamp(22px,3.4vw,34px);text-align:center;margin:0 0 18px;line-height:1.2}
.gq-ring{margin:6px 0 22px}
.ring.big{width:120px;height:120px}
.ring.big::before{inset:11px}
.ring.big span{font-size:42px}
.ring.sm{width:52px;height:52px}
.ring.sm::before{inset:5px}
.ring.sm span{font-size:20px}
.game-host .answers{width:100%;max-width:900px;margin-top:0}
.answers.showonly .ans{cursor:default}
.answers.showonly .ans:hover{transform:none;filter:none}
.g-bar{position:absolute;left:14px;right:14px;bottom:10px;height:8px;border-radius:999px;background:rgba(0,0,0,.30);overflow:visible}
.g-bar i{display:block;height:100%;border-radius:999px;background:rgba(255,255,255,.9);transition:width .6s cubic-bezier(.22,1,.36,1)}
.g-bar b{position:absolute;right:0;top:-21px;font-size:14px;font-weight:900}
.g-check{position:absolute;top:8px;right:12px;font-size:26px;font-weight:900}
.gr-main{display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;margin-top:10px}
.gr-q .answers{margin-top:14px}
.gr-lb{background:rgba(0,0,0,.18);border:1px solid var(--line);border-radius:var(--r);padding:16px}
.gr-lb .sb-row{padding:10px 12px;margin-bottom:8px;animation:none}
.gr-lb .rk{font-weight:900;width:24px;color:var(--muted)}
.gr-lb .av{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;font-size:18px;flex:none}
.gr-lb .nm{flex:1;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.gr-lb .sc{font-weight:900;color:var(--accent)}
/* player */
.game-join .gj-card{background:linear-gradient(160deg,rgba(123,47,247,.16),rgba(47,107,255,.08));border:1px solid var(--line);border-radius:var(--r);padding:22px;text-align:center}
.gj-title{font-size:20px;font-weight:800;margin-bottom:4px}
.gj-sub{color:var(--muted);margin-bottom:14px}
.gj-avs{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin:14px 0 18px}
.gj-av{font-size:24px;padding:8px 0;border-radius:12px;border:1.5px solid var(--line);background:rgba(0,0,0,.2);cursor:pointer;transition:transform .12s,border-color .12s,background .12s}
.gj-av:hover{transform:translateY(-2px)}
.gj-av.on{border-color:var(--accent);background:rgba(255,210,63,.14);transform:translateY(-2px)}
.game-wait{display:flex;justify-content:center;align-items:center;min-height:60vh}
.gw-card{text-align:center;background:rgba(0,0,0,.18);border:1px solid var(--line);border-radius:var(--r);padding:34px 28px;max-width:360px}
.gw-av{font-size:64px;animation:pop .4s both}
.gw-name{font-size:24px;font-weight:900;margin:8px 0 14px}
.gw-msg{font-size:20px;font-weight:800;color:#37e08a}
.gw-sub{color:var(--muted);margin-top:6px}
.gw-count{margin-top:18px;color:var(--muted)}
.gw-count b{color:var(--accent);font-size:20px}
.game-play .gp-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.gp-q{font-size:clamp(20px,3.2vw,28px);text-align:center;margin:0 0 18px;line-height:1.25}
.gp-answers .ans{min-height:clamp(72px,16vh,120px)}
.gp-ans.chosen{box-shadow:0 0 0 4px #fff,0 7px 0 rgba(0,0,0,.28)}
.gp-locked{text-align:center;padding:40px 16px}
.gp-lock-ic{width:90px;height:90px;margin:0 auto 16px;display:grid;place-items:center;background:rgba(255,255,255,.08);border-radius:24px}
.gp-lock-ic svg{width:50%;height:50%;fill:#fff}
.gp-lock-msg{font-size:22px;font-weight:900;color:#37e08a}
.gp-lock-sub{color:var(--muted);margin-top:6px}
.game-result{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60vh}
.gres{text-align:center;padding:30px;border-radius:var(--r);max-width:380px;width:100%}
.gres.ok{background:rgba(24,189,107,.12);border:1px solid rgba(24,189,107,.4)}
.gres.no{background:rgba(255,90,95,.12);border:1px solid rgba(255,90,95,.4)}
.gres.miss{background:rgba(255,255,255,.05);border:1px solid var(--line)}
.gres-ic{font-size:60px;font-weight:900}
.gres-msg{font-size:26px;font-weight:900;margin-top:4px}
.gres-pts{font-size:34px;font-weight:900;color:var(--accent);margin-top:8px}
.gres-streak{margin-top:8px;font-weight:800;color:#ff8c42}
.gres-rank{margin-top:14px;color:var(--muted);font-weight:700}
.gres-rank b{color:var(--text)}
@media(max-width:820px){ .gl-main{grid-template-columns:1fr} .gr-main{grid-template-columns:1fr} .gj-avs{grid-template-columns:repeat(8,1fr)} }

/* ---------- post-game report ---------- */
.report-wrap{max-width:1000px}
.report-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:6px}
.report-actions{display:flex;gap:8px;flex-wrap:wrap}
.report-title{margin:6px 0 18px;font-size:clamp(20px,3vw,28px)}
.report-h3{margin:26px 0 12px;font-size:16px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;font-weight:800}
.rcards{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
.rcard{background:rgba(255,255,255,.05);border:1px solid var(--line);border-radius:var(--r);padding:16px;text-align:center}
.rc-v{font-size:26px;font-weight:900;color:var(--accent)}
.rc-l{font-size:12px;color:var(--muted);margin-top:4px}
.rq-list{display:flex;flex-direction:column;gap:12px}
.rq{background:rgba(255,255,255,.04);border:1px solid var(--line);border-radius:14px;padding:14px 16px}
.rq-top{display:flex;align-items:baseline;gap:10px}
.rq-n{font-weight:900;color:var(--muted);flex:none}
.rq-txt{flex:1;font-weight:700}
.rq-diff{font-weight:800;font-size:13px;flex:none}
.rq-diff.easy{color:#37e08a}.rq-diff.med{color:#ffd23f}.rq-diff.hard{color:#ff8488}
.rq-bar{height:10px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden;margin:10px 0 8px}
.rq-bar i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--teal),var(--accent))}
.rq-meta{font-size:13px;color:var(--muted)}
.rq-dist{display:flex;flex-direction:column;gap:6px;margin-top:10px}
.rq-opt{display:flex;align-items:center;gap:8px;font-size:13px}
.rq-opt.ok .rq-ol{color:#37e08a;font-weight:800}
.rq-ol{flex:0 0 38%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rq-ob{flex:1;height:14px;border-radius:999px;background:rgba(255,255,255,.07);overflow:hidden}
.rq-ob i{display:block;height:100%;border-radius:999px}
.rq-opt b{flex:none;width:28px;text-align:right;font-weight:800}
.rtable-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:var(--r)}
.rtable{min-width:520px}
.rt-row{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.06)}
.rt-row:last-child{border-bottom:none}
.rt-head{background:rgba(255,255,255,.05);font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;font-weight:800;position:sticky;top:0}
.rt-rank{width:24px;text-align:center;font-weight:900;flex:none}
.rt-av{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;font-size:16px;flex:none}
.rt-head .rt-av{background:transparent}
.rt-name{flex:1;min-width:90px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rt-score{width:64px;text-align:right;font-weight:900;color:var(--accent);flex:none}
.rt-acc{width:48px;text-align:right;flex:none;color:var(--muted)}
.rt-grid{display:flex;gap:4px;flex:none;padding-left:6px}
.rt-q{width:26px;text-align:center;font-weight:800;color:var(--muted)}
.rt-cell{width:26px;height:26px;border-radius:7px;display:grid;place-items:center;font-weight:900;font-size:13px;flex:none}
.rt-cell.ok{background:rgba(24,189,107,.22);color:#37e08a}
.rt-cell.no{background:rgba(255,90,95,.18);color:#ff8488}
.rt-cell.none{background:rgba(255,255,255,.05);color:var(--muted2)}
@media(max-width:760px){ .rcards{grid-template-columns:repeat(2,1fr)} }
@media print{
  body{background:#fff;color:#111}
  #topbar,.report-head,.no-print{display:none!important}
  .report-wrap{max-width:none;padding:0}
  .rcard,.rq,.rtable-wrap{border-color:#ccc;background:#fff}
  .rc-v,.rt-score{color:#111}
  .rt-cell.ok{background:#d8f5e6}.rt-cell.no{background:#fde0e1}.rt-cell.none{background:#f0f0f0}
}

/* ---------- self-paced mode ---------- */
.a-stats{display:flex;gap:10px;margin-bottom:18px}
.a-stat{flex:1;background:rgba(255,255,255,.05);border:1px solid var(--line);border-radius:12px;padding:14px 8px;text-align:center}
.a-stat b{display:block;font-size:26px;font-weight:900;color:var(--accent);line-height:1}
.a-stat span{font-size:11px;color:var(--muted);margin-top:4px;display:block}
.ap-answers .ans,.answers.ap-answers .ans{min-height:clamp(72px,15vh,118px)}
.ap-ans.chosen{box-shadow:0 0 0 4px #fff,0 7px 0 rgba(0,0,0,.28)}
.ap-verdict{text-align:center;font-size:clamp(20px,5vw,26px);font-weight:900;padding:14px;border-radius:14px;margin-bottom:16px}
.ap-verdict.ok{background:rgba(24,189,107,.14);color:#37e08a;border:1px solid rgba(24,189,107,.4)}
.ap-verdict.no{background:rgba(255,90,95,.14);color:#ff8488;border:1px solid rgba(255,90,95,.4)}

/* ---------- PWA bar ---------- */
.pwa-bar{display:flex;align-items:center;gap:10px;padding:9px 16px;font-size:13px;font-weight:700;line-height:1.3}
.pwa-bar.hidden{display:none}
.pwa-bar.off{background:#3a1020;color:#ffb4c0;border-bottom:1px solid rgba(255,90,95,.3);justify-content:center;text-align:center}
.pwa-bar.install{background:rgba(46,230,196,.1);color:var(--teal);border-bottom:1px solid rgba(46,230,196,.25)}
.pwa-bar.install span{flex:1}
.pwa-btn{background:var(--teal);color:#04201a;border:none;border-radius:8px;padding:6px 14px;font-weight:800;cursor:pointer;font-size:13px;flex:none}
.pwa-btn:hover{filter:brightness(1.08)}
.pwa-x{background:transparent;border:none;color:inherit;cursor:pointer;font-size:14px;opacity:.65;padding:4px;flex:none}
.pwa-x:hover{opacity:1}
@media print{ .pwa-bar{display:none!important} }

/* ---------- rating / NPS ---------- */
.rt-head{text-align:center;margin-bottom:18px}
.rt-big{font-size:clamp(40px,9vw,64px);font-weight:900;color:var(--accent);line-height:1}
.rt-star{color:#ffd23f}
.rt-lbl{font-size:13px;color:var(--muted);margin-top:4px}
.rt-sub{font-size:13px;margin-top:8px;font-weight:700}
.rt-prom{color:#37e08a}.rt-pass{color:#ffd23f}.rt-det{color:#ff8488}
.rt-bars{display:flex;flex-direction:column;gap:8px;max-width:560px;margin:0 auto}
.rt-row{display:flex;align-items:center;gap:10px}
.rt-v{flex:0 0 96px;text-align:right;color:#ffd23f;font-weight:800;letter-spacing:1px;white-space:nowrap;overflow:hidden}
.rt-bar{flex:1;height:18px;border-radius:999px;background:rgba(255,255,255,.07);overflow:hidden}
.rt-bar i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--teal),var(--accent));transition:width .4s}
.rt-c{flex:0 0 78px;text-align:right;font-weight:800;color:var(--muted)}
/* ---------- ranking results ---------- */
.rk-list{display:flex;flex-direction:column;gap:8px;max-width:620px;margin:0 auto}
.rk-row{display:flex;align-items:center;gap:10px}
.rk-pos{flex:none;width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.08);display:grid;place-items:center;font-weight:900;color:var(--accent)}
.rk-label{flex:0 0 34%;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rk-bar{flex:1;height:18px;border-radius:999px;background:rgba(255,255,255,.07);overflow:hidden}
.rk-bar i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--accent),var(--teal));transition:width .4s}
.rk-avg{flex:none;width:52px;text-align:right;font-weight:800}
.rk-foot{text-align:center;color:var(--muted);font-size:12px;margin-top:14px}
/* ---------- participant: rating input ---------- */
.rate-stars{display:flex;justify-content:center;gap:8px}
.rate-star{font-size:clamp(36px,11vw,56px);background:none;border:none;color:#5a4a78;cursor:pointer;line-height:1;transition:transform .1s,color .15s;padding:4px}
.rate-star:hover,.rate-star:hover~.rate-star{color:#5a4a78}
.rate-stars:hover .rate-star{color:#ffd23f}
.rate-star:hover~.rate-star{color:#5a4a78}
.rate-star:active{transform:scale(.9)}
.rate-nps{display:grid;grid-template-columns:repeat(6,1fr);gap:8px}
.rate-np{aspect-ratio:1;background:rgba(255,255,255,.05);border:1px solid var(--line);border-radius:12px;color:var(--ink-1,#fff);font-weight:800;font-size:18px;cursor:pointer;transition:transform .1s,background .15s}
.rate-np:hover{background:rgba(46,230,196,.18);border-color:var(--teal)}
.rate-np:active{transform:scale(.92)}
.rate-nps-lbl{display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-top:8px}
/* ---------- participant: ranking input ---------- */
.rank-list{display:flex;flex-direction:column;gap:8px;margin-bottom:14px}
.rank-item{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.05);border:1px solid var(--line);border-radius:12px;padding:10px 12px}
.rank-pos{flex:none;width:26px;height:26px;border-radius:50%;background:var(--accent);color:#fff;display:grid;place-items:center;font-weight:900;font-size:13px}
.rank-txt{flex:1;font-weight:700}
.rank-ctrls{flex:none;display:flex;gap:4px}
.rank-mv{width:34px;height:34px;border-radius:9px;background:rgba(255,255,255,.08);border:1px solid var(--line);color:#fff;font-size:13px;cursor:pointer}
.rank-mv:disabled{opacity:.3;cursor:default}
.rank-mv:not(:disabled):active{background:var(--teal);color:#04201a}
/* ---------- segmented toggle ---------- */
.seg{display:inline-flex;background:rgba(255,255,255,.05);border:1px solid var(--line);border-radius:10px;padding:3px;gap:3px}
.seg-btn{background:none;border:none;color:var(--muted);font-weight:800;padding:7px 14px;border-radius:8px;cursor:pointer;font-size:13px}
.seg-btn.on{background:var(--accent);color:#fff}
/* ---------- Q&A moderation ---------- */
.qa-card.is-star{border-color:rgba(255,210,63,.55);box-shadow:0 0 0 1px rgba(255,210,63,.25)}
.qa-card.is-answered{opacity:.62}
.qa-flag{color:#ffd23f;margin-right:6px}
.qa-ans-badge{display:inline-block;background:rgba(24,189,107,.18);color:#37e08a;font-size:11px;font-weight:800;padding:1px 7px;border-radius:999px;margin-right:6px;vertical-align:middle}
.iconbtn.sm.on{background:var(--accent);color:#fff;border-color:var(--accent)}

/* ---------- scales (Likert) ---------- */
.sc-list{display:flex;flex-direction:column;gap:14px;max-width:620px;margin:0 auto}
.sc-row .sc-top{display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-bottom:5px}
.sc-stmt{font-weight:700}
.sc-avg{font-weight:900;color:var(--accent);flex:none}
.sc-track{height:16px;border-radius:999px;background:rgba(255,255,255,.07);overflow:hidden;position:relative}
.sc-track i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#ff8488,#ffd23f,#37e08a);transition:width .4s}
.sc-foot{display:flex;justify-content:space-between;color:var(--muted);font-size:12px;margin-top:14px;max-width:620px;margin-left:auto;margin-right:auto}
.scq{margin-bottom:14px}
.scq-label{font-weight:700;margin-bottom:8px}
.scq-dots{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
.scq-dot{aspect-ratio:1;background:rgba(255,255,255,.05);border:1px solid var(--line);border-radius:12px;color:#fff;font-weight:800;font-size:18px;cursor:pointer;transition:transform .1s,background .15s}
.scq-dot:hover:not(:disabled){background:rgba(46,230,196,.16);border-color:var(--teal)}
.scq-dot.on{background:var(--accent);border-color:var(--accent)}
.scq-dot:active:not(:disabled){transform:scale(.92)}
.scq-ends{display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-top:8px}
/* ---------- 100 points ---------- */
.pt-list{display:flex;flex-direction:column;gap:8px;max-width:620px;margin:0 auto}
.pt-row{display:flex;align-items:center;gap:10px}
.pt-label{flex:0 0 32%;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pt-bar{flex:1;height:18px;border-radius:999px;background:rgba(255,255,255,.07);overflow:hidden}
.pt-bar i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--teal),var(--accent));transition:width .4s}
.pt-val{flex:none;width:78px;text-align:right;font-weight:900}
.pt-pct{color:var(--muted);font-weight:700;font-size:12px}
.pt-foot{text-align:center;color:var(--muted);font-size:12px;margin-top:14px}
.pt-budget{text-align:center;font-weight:800;margin-bottom:14px;font-size:15px}
.pt-budget b{color:var(--accent);font-size:20px}.pt-budget b.pt-ok{color:#37e08a}
.ptq{display:flex;align-items:center;justify-content:space-between;gap:10px;background:rgba(255,255,255,.05);border:1px solid var(--line);border-radius:12px;padding:10px 12px;margin-bottom:8px}
.ptq-label{flex:1;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ptq-ctrl{flex:none;display:flex;align-items:center;gap:10px}
.ptq-val{min-width:34px;text-align:center;font-weight:900;font-size:18px}
.pt-step{width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,.08);border:1px solid var(--line);color:#fff;font-size:20px;font-weight:800;cursor:pointer;line-height:1}
.pt-step:disabled{opacity:.3;cursor:default}
.pt-step:not(:disabled):active{background:var(--teal);color:#04201a}

/* ---------- presentation deck ---------- */
.live-mode-seg{display:flex;justify-content:center;margin:0 0 14px}
.deck-added-h{font-size:13px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin:4px 0 10px}
.deck-empty{color:var(--muted);font-size:13px;padding:14px;text-align:center;border:1px dashed var(--line);border-radius:12px}
.deck-slides{display:flex;flex-direction:column;gap:8px}
.deck-srow{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.05);border:1px solid var(--line);border-radius:12px;padding:9px 12px}
.ds-ic{flex:none;font-size:18px}
.ds-num{flex:none;width:22px;height:22px;border-radius:50%;background:var(--accent);color:#fff;display:grid;place-items:center;font-weight:900;font-size:12px}
.ds-txt{flex:1;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ds-ctrls{flex:none;display:flex;gap:4px;align-items:center}
.deck-compose{margin-top:16px;padding-top:16px;border-top:1px dashed var(--line)}
.deck-compose-h{font-size:14px;font-weight:800;margin-bottom:12px;color:var(--teal)}
.deck-nav{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.deck-ind{font-weight:800;color:var(--accent);font-size:14px}
.jv-deck{display:inline-block;background:rgba(46,230,196,.14);color:var(--teal);font-size:12px;font-weight:800;padding:3px 10px;border-radius:999px;margin-bottom:8px}

/* ---------- Q&A moderation ---------- */
.qa-card.is-pending{border-color:rgba(255,180,60,.5);background:rgba(255,180,60,.06)}
.qa-pend-badge{display:inline-block;background:rgba(255,180,60,.2);color:#ffcf7a;font-size:11px;font-weight:800;padding:1px 8px;border-radius:999px;margin-right:6px;vertical-align:middle}
.qa-pend-bar{background:rgba(255,180,60,.12);color:#ffcf7a;font-weight:800;font-size:13px;padding:8px 12px;border-radius:10px;margin-bottom:10px;text-align:center}
.iconbtn.sm.approve{width:auto;padding:0 10px;background:rgba(24,189,107,.16);color:#37e08a;border-color:rgba(24,189,107,.4);font-weight:800;white-space:nowrap}
.iconbtn.sm.approve:hover{background:rgba(24,189,107,.28)}
/* ---------- deck import/export ---------- */
.deck-io{display:flex;gap:8px;margin-top:12px}
.deck-io .btn{flex:1}

/* ---------- spinner wheel ---------- */
.spin-layout{display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start}
.spin-stage{display:flex;flex-direction:column;align-items:center;gap:16px}
.spin-wrap{position:relative;width:min(80vw,420px);aspect-ratio:1}
.spin-wheel{width:100%;height:100%;will-change:transform;filter:drop-shadow(0 10px 30px rgba(0,0,0,.4))}
.spin-svg{width:100%;height:100%;display:block}
.spin-pointer{position:absolute;top:-6px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:16px solid transparent;border-right:16px solid transparent;border-top:28px solid #fff;z-index:3;filter:drop-shadow(0 2px 3px rgba(0,0,0,.5))}
.spin-go{min-width:200px}
.spin-result{font-size:clamp(20px,4vw,28px);font-weight:900}
.spin-result b{color:var(--accent)}
.spin-result-ph{min-height:34px}
.spin-need{display:grid;place-items:center;width:100%;height:100%;border:2px dashed var(--line);border-radius:50%;color:var(--muted);text-align:center;padding:20px}
.spin-side{display:flex;flex-direction:column;gap:10px}
.spin-lbl{font-size:13px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.4px}
.spin-ta{font-family:inherit;resize:vertical;line-height:1.7}
.spin-chk{display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer}
.spin-btns{display:flex;gap:8px}
.spin-btns .btn{flex:1}
@media(max-width:760px){ .spin-layout{grid-template-columns:1fr} }

/* ---------- free-answer editor ---------- */
.acc-rows{display:flex;flex-direction:column;gap:8px}
.acc-row{display:flex;align-items:center;gap:10px}
.acc-ic{flex:none;width:30px;height:30px;border-radius:9px;display:grid;place-items:center;font-weight:900;background:rgba(46,230,196,.14);color:var(--teal)}
.acc-row .acc-text{flex:1}
.acc-hint{margin-top:10px;font-size:13px;color:var(--muted);line-height:1.5}

/* ---------- free-answer play ---------- */
.type-input-wrap{display:flex;flex-direction:column;gap:12px;max-width:520px;margin:8px auto 0;width:100%}
.type-input{font-size:clamp(18px,4vw,24px);text-align:center;padding:16px;font-weight:700;border-width:2px}
.type-input:focus{border-color:var(--teal)}
.type-submit{font-size:18px;padding:14px}
.you-wrote{color:var(--muted);font-weight:700;margin-top:2px}
.you-wrote b{color:#ff8488;text-decoration:line-through}

/* ---------- free-answer live (game + assign) ---------- */
.gp-type-wrap{display:flex;flex-direction:column;gap:12px;margin-top:8px}
.gp-type-input{font-size:20px;text-align:center;padding:16px;font-weight:700;border-width:2px}
.gp-type-input:focus{border-color:var(--teal)}
.gp-lock-your{font-size:18px;font-weight:800;color:#fff;margin:2px 0 8px}
.gres-canon,.ap-canon{margin-top:8px;font-weight:700;color:var(--muted)}
.gres-canon b,.ap-canon b{color:var(--teal)}
.gh-typing{display:flex;flex-direction:column;align-items:center;gap:14px;padding:36px 12px;color:var(--muted)}
.gh-typing-ic{font-size:56px;animation:pulse 1.6s ease-in-out infinite}
.gh-typing-msg{font-size:18px;font-weight:700}
.gh-canon{font-size:clamp(16px,2.4vw,22px);font-weight:800;margin-bottom:14px;color:#fff}
.gh-canon b{color:var(--teal)}
.gh-subs{display:flex;flex-direction:column;gap:8px;max-height:52vh;overflow:auto}
.gh-sub{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.05);border:1px solid var(--line);border-radius:10px;padding:8px 12px}
.gh-sub.ok{border-color:rgba(24,189,107,.5);background:rgba(24,189,107,.1)}
.ghs-txt{flex:none;min-width:120px;font-weight:800}
.ghs-bar{flex:1;height:10px;background:rgba(255,255,255,.08);border-radius:6px;overflow:hidden}
.ghs-bar i{display:block;height:100%;background:var(--accent2,#7c5cff);border-radius:6px}
.gh-sub.ok .ghs-bar i{background:var(--teal)}
.gh-sub b{flex:none;min-width:22px;text-align:right;font-weight:900}
@keyframes pulse{0%,100%{transform:scale(1);opacity:.85}50%{transform:scale(1.12);opacity:1}}

/* ---------- shuffle toggle + nickname ---------- */
.setup-shuffle{display:flex;align-items:center;gap:9px;font-size:14px;font-weight:700;cursor:pointer;margin:4px 0 14px;color:var(--ink)}
.setup-shuffle input{width:18px;height:18px;accent-color:var(--accent)}
.nick-row{display:flex;gap:8px;align-items:stretch}
.nick-row .big-input{flex:1}
.nick-btn{flex:none;width:52px;border:1px solid var(--line);background:rgba(255,255,255,.05);border-radius:12px;font-size:24px;cursor:pointer;transition:transform .15s,background .15s}
.nick-btn:hover{background:rgba(255,255,255,.1)}
.nick-btn:active{transform:rotate(90deg) scale(.9)}

/* ---------- emoji reactions ---------- */
.react-bar{position:fixed;left:0;right:0;bottom:16px;display:flex;justify-content:center;gap:8px;z-index:25;pointer-events:none}
.react-btn{pointer-events:auto;width:50px;height:50px;border-radius:50%;border:1px solid var(--line);background:rgba(20,10,40,.72);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);font-size:25px;line-height:1;cursor:pointer;transition:transform .14s;box-shadow:0 4px 14px rgba(0,0,0,.35)}
.react-btn:hover{transform:translateY(-2px)}
.react-btn:active,.react-btn.pop{transform:scale(1.4)}
.react-overlay{position:fixed;inset:0;pointer-events:none;z-index:60;overflow:hidden}
.react-float{position:absolute;bottom:70px;font-size:40px;animation:reactRise var(--dur,3s) ease-out forwards;will-change:transform,opacity}
@keyframes reactRise{0%{transform:translate(0,0) scale(.5);opacity:0}12%{opacity:1;transform:translate(0,-26px) scale(1.15)}100%{transform:translate(var(--dx,0),-72vh) scale(1);opacity:0}}

/* ---------- numeric editor ---------- */
.num-rows{display:flex;flex-direction:column;gap:12px}
.num-row{display:flex;align-items:center;gap:12px}
.num-lbl{flex:none;min-width:150px;font-size:14px;font-weight:800;color:var(--muted)}
.num-pm{font-size:22px;font-weight:900;color:var(--teal)}
.num-target,.num-tol{max-width:200px;font-size:18px;font-weight:800;font-variant-numeric:tabular-nums}

/* ---------- team mode ---------- */
.setup-teams{margin:0 0 14px;padding:12px 14px;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.03)}
.setup-teams .chk{display:flex;align-items:center;gap:9px;font-size:14px;font-weight:700;cursor:pointer}
.setup-teams .chk input{width:18px;height:18px;accent-color:var(--accent)}
.team-count{display:flex;gap:8px;margin-top:10px}
.tc-btn{flex:1;padding:8px;border:1px solid var(--line);background:rgba(255,255,255,.04);border-radius:9px;font-weight:800;cursor:pointer;color:var(--ink)}
.tc-btn.on{background:var(--accent);color:#fff;border-color:var(--accent)}
.gj-tlabel{font-size:13px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin:14px 0 8px}
.gj-teams{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px}
.gj-team{display:flex;align-items:center;justify-content:center;gap:6px;padding:12px;border:2px solid var(--line);border-radius:12px;background:rgba(255,255,255,.04);font-weight:800;font-size:15px;cursor:pointer;color:var(--ink);transition:all .15s}
.gj-team.on{border-color:var(--tc);background:color-mix(in srgb,var(--tc) 22%,transparent);box-shadow:0 0 0 3px color-mix(in srgb,var(--tc) 25%,transparent)}
.gw-team{margin-top:6px;font-weight:900;font-size:16px;padding:4px 14px;border-radius:999px;background:color-mix(in srgb,var(--tc) 20%,transparent);border:1px solid var(--tc);color:#fff}
.gl-teams{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0 12px}
.gl-team{font-weight:800;font-size:13px;padding:5px 12px;border-radius:999px;background:color-mix(in srgb,var(--tc) 16%,transparent);border:1px solid var(--tc)}
.team-standings{max-width:460px;margin:20px auto 6px;text-align:left}
.ts-title{font-weight:900;font-size:16px;margin-bottom:10px;text-align:center;color:var(--muted)}
.ts-row{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:12px;margin-bottom:8px;background:rgba(255,255,255,.05);border-left:5px solid var(--tc)}
.ts-row.win{background:color-mix(in srgb,var(--tc) 18%,transparent);box-shadow:0 4px 16px color-mix(in srgb,var(--tc) 25%,transparent)}
.ts-rk{font-weight:900;font-size:20px;min-width:26px;color:var(--muted)}
.ts-emoji{font-size:24px}
.ts-name{flex:1;font-weight:800;font-size:17px}
.ts-sc{font-weight:900;font-size:20px;font-variant-numeric:tabular-nums}
.gres-team{margin-top:10px;font-weight:900;font-size:16px;padding:5px 14px;border-radius:999px;display:inline-block;background:color-mix(in srgb,var(--tc) 22%,transparent);border:1px solid var(--tc);color:#fff}

/* ---------- in-app help / manual ---------- */
.help-wrap{max-width:940px}
.help-books{display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap}
.help-book{padding:10px 18px;border:1px solid var(--line,#2a2350);background:rgba(255,255,255,.04);border-radius:10px;font-weight:800;cursor:pointer;color:var(--ink,#f4f2ff)}
.help-book.on{background:var(--accent,#7c5cff);color:#fff;border-color:var(--accent,#7c5cff)}
.help-layout{display:grid;grid-template-columns:230px 1fr;gap:26px;align-items:start}
.help-toc{position:sticky;top:16px;display:flex;flex-direction:column;gap:2px;max-height:calc(100vh - 40px);overflow:auto;border-right:1px solid var(--line,#2a2350);padding-right:12px}
.help-toc-item{font-size:13px;font-weight:700;color:var(--muted,#9a93c0);padding:6px 8px;border-radius:7px;cursor:pointer;text-decoration:none;line-height:1.3;transition:background .12s}
.help-toc-item:hover{background:rgba(255,255,255,.06);color:var(--ink,#f4f2ff)}
.md-body{min-width:0;line-height:1.65;font-size:15px}
.md-body .md-h1{font-size:26px;font-weight:900;margin:0 0 6px;line-height:1.2}
.md-body .md-h2{font-size:21px;font-weight:800;margin:30px 0 10px;padding-top:12px;border-top:1px solid var(--line,#2a2350);scroll-margin-top:14px}
.md-body .md-h3{font-size:17px;font-weight:800;margin:18px 0 6px}
.md-body .md-h4{font-size:15px;font-weight:800;margin:14px 0 4px;color:var(--muted,#9a93c0)}
.md-body p{margin:0 0 12px}
.md-body .md-ul,.md-body .md-ol{margin:0 0 12px;padding-left:22px}
.md-body li{margin:5px 0}
.md-body code{background:rgba(255,255,255,.08);padding:2px 6px;border-radius:5px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.88em}
.md-body .md-pre{background:rgba(0,0,0,.30);border:1px solid var(--line,#2a2350);border-radius:10px;padding:14px 16px;overflow:auto;margin:0 0 14px}
.md-body .md-pre code{background:none;padding:0;font-size:13px;line-height:1.55;white-space:pre;display:block}
.md-body .md-bq{border-left:4px solid var(--accent,#7c5cff);background:rgba(255,255,255,.03);padding:10px 16px;margin:0 0 14px;border-radius:0 8px 8px 0;color:var(--muted,#9a93c0)}
.md-body .md-hr{border:none;border-top:1px solid var(--line,#2a2350);margin:22px 0}
.md-body .md-tbl{width:100%;border-collapse:collapse;margin:0 0 14px;font-size:14px}
.md-body .md-tbl th,.md-body .md-tbl td{border:1px solid var(--line,#2a2350);padding:8px 10px;text-align:left;vertical-align:top}
.md-body .md-tbl th{background:rgba(255,255,255,.05);font-weight:800}
.md-body .md-cb{color:var(--accent,#7c5cff);font-weight:900}
.md-body a{color:var(--accent,#7c5cff)}
@media(max-width:760px){ .help-layout{grid-template-columns:1fr} .help-toc{position:static;max-height:200px;border-right:none;border-bottom:1px solid var(--line,#2a2350);padding-right:0;padding-bottom:10px;margin-bottom:14px} }
</style>
</head>
<body>
<div class="bg"><b></b><b></b><b></b></div>

<header class="topbar" id="topbar"></header>
<div id="pwa-bar" class="pwa-bar hidden"></div>
<main id="root"></main>

<canvas id="confetti" class="hidden"></canvas>

<script type="text/markdown" id="manual-user-en">
# Undava — User manual

*A complete, step-by-step guide to creating quizzes, playing in class, and running audience activities.*

---

## Contents

1. [What Undava is](#1-what-undava-is)
2. [The two editions and when to use each](#2-the-two-editions)
3. [First steps](#3-first-steps)
4. [Interface tour](#4-interface-tour)
5. [The quiz library](#5-the-quiz-library)
6. [Creating a quiz in the editor](#6-creating-a-quiz)
7. [The four question types](#7-the-four-question-types)
8. [Import and export (JSON)](#8-import-and-export)
9. [Game modes](#9-game-modes)
10. [Solo game — step by step](#10-solo-game)
11. [Pass & play game — step by step](#11-pass-and-play)
12. [Live synchronous game — the host](#12-live-game-host)
13. [Live synchronous game — the participant](#13-live-game-participant)
14. [Team mode](#14-team-mode)
15. [Reactions, nicknames, shuffle](#15-reactions-nicknames-shuffle)
16. [Self-paced homework](#16-self-paced)
17. [Audience activities (Slido/Mentimeter)](#17-audience-activities)
18. [Presentations (deck)](#18-presentations)
19. [Post-game reports](#19-reports)
20. [The spinner wheel](#20-spinner-wheel)
21. [Install as an app (PWA)](#21-install-pwa)
22. [Tips and best practices](#22-tips)
23. [Troubleshooting for users](#23-troubleshooting)
24. [Privacy](#24-privacy)

---

## 1. What Undava is

Undava is a quiz game in the spirit of Kahoot, combined with audience activities in the style of Slido/Mentimeter. You can create quizzes, play them alone or with a group (on a single device or on many, in sync), and run live activities (word cloud, polls, questions from the audience).

The app is **offline-first**, **telemetry-free**, requires **no mandatory accounts for participants**, and is **trade-free**. Your quizzes belong to you and are saved locally or as JSON files you can move anywhere.

---

## 2. The two editions

The app comes in two forms. Both are called "Undava" and use the **same JSON format** for quizzes, so you can move them freely between the two.

### `quiz-fara-frontiere.html` — the fully offline edition

- A single HTML file. Open it with a double-click, **no install, no internet, no server**.
- Ideal for: preparing at home, solo games, "pass & play" games (several players in turn, on the same device), classroom without a network.
- Contains: the **4 question types**, the **Solo** and **Pass & play** games, **shuffling** of questions and options, and the **Spinner wheel**.
- Quizzes are saved in the browser's local storage (and via JSON export).

### `index.php` — the server (full) edition

- Must be hosted on a server with PHP (see the *Administrator manual*). It does **not** open directly with a double-click.
- Contains everything the offline edition has **plus** the features that need a network:
  - **Live synchronous game** (many players, on their own devices, in real time);
  - **Self-paced homework** (everyone solves at their own pace);
  - **Audience activities** (word cloud, poll, moderated Q&A, rating/NPS, ranking, scales, 100 points);
  - **Presentations** with several activities across slides;
  - **Team mode**, **emoji reactions**, **join codes + QR**, post-game **reports**, **install as an app (PWA)**.

> **In short:** if you just want to create and practice alone or with friends on a laptop, the HTML file is enough. If you want a live game with a full room, you need the server edition.

---

## 3. First steps

### A. The offline edition (HTML)

1. Get the `quiz-fara-frontiere.html` file.
2. Double-click it. It opens in your default browser.
3. Done — you're on the home screen. Nothing else is needed.

### B. The server edition (index.php)

1. The administrator gives you a **web address** (e.g. `https://example.com/quiz/`).
2. Open the address in a browser.
3. To **create and host** live games, you'll need the **host password** from the administrator (see section 12). As a **participant**, you don't need a password.

### Interface language

The app speaks 7 languages (Romanian, English, French, Italian, Spanish, Portuguese, German). Switch the language from the top-right corner. Your choice is remembered.

---

## 4. Interface tour

The home screen has, from top to bottom:

- **The top bar** — the "Undava" logo (clicking it brings you back Home), the language switcher, and the sound toggle.
- **The title** and a short subtitle.
- **The large action buttons:**
  - **Play** — opens the quiz library, where you choose what to play.
  - **Create** — opens the editor for a new quiz.
  - **Import** — load a quiz from a JSON file.
  - **Wheel** — opens the Spinner wheel (random picker).
- **Features** shown as badges: offline, free, private, open.

> On a phone, the buttons stack vertically and all content adapts to the small screen.

---

## 5. The quiz library

You tap **Play** and land in the library. Here you see:

- **Sample quizzes** (marked "DEMO") — ready-made examples you can use to test the app right away.
- **Your quizzes** (marked "YOURS") — the ones you created or imported.

Each quiz appears as a colored card showing the title, description and **number of questions**. On each card you have options to:

- **Play** — starts the game setup (you choose the mode).
- **Edit** — opens the quiz in the editor.
- **Export** — saves the quiz as a JSON file.
- **Delete** — removes the quiz (only for your quizzes; demos can't be deleted).

> A sample quiz can't be edited directly: if you tap Edit on a demo, the app makes you an editable **copy** (marked "✎"), so you don't damage the original.

---

## 6. Creating a quiz

You tap **Create** (or **Edit** on an existing quiz). The editor has two areas: the quiz header and the list of questions.

### Step 1 — Title and description

1. In the **Title** field, write the quiz name (required, up to 80–200 characters).
2. In the **Description** field, optionally write a short summary (what the quiz covers).

### Step 2 — Adding questions

- A new quiz starts with one empty question.
- Tap **＋ Add question** to add more.
- You can have **up to 100 questions** in a quiz.

### Step 3 — Configuring each question

Each question has, in its header:

- A **type selector** (Multiple choice / True-False / Free answer / Numeric) — see section 7.
- A **time selector** — how long players have to answer: **5, 10, 20, 30, 60 or 120 seconds**.
- A **points selector** — **Standard (1000)** or **Double (2000)** for harder questions.
- **↑ / ↓** buttons to reorder the question in the list.
- The **🗑** button to delete the question.

In the question body you write the **question text** (up to 200–500 characters) and configure the answers according to the type.

### Step 4 — Saving

You tap **✓ Save**. The app first checks:

- that the quiz has a title;
- that each question has text;
- that each question has its answers filled in correctly for its type (see below).

If something is missing, you get a clear message telling you which question to fix. After saving, the quiz appears in the library under "YOURS".

> **Offline note:** in the HTML edition, quizzes are saved in the browser's local storage. If you clear the browser's data or use a different browser/device, you no longer see them — that's why it's important to **export** them (section 8) as a backup.

---

## 7. The four question types

You choose the type from the selector in the question header.

### 7.1. Multiple choice (4 options)

The classic question with multiple options.

1. Write the question text.
2. Fill in the answer options (**2 to 4**).
3. Tick **Correct** next to the correct option (exactly one).
4. With **✕** you remove an option; with **Add answer** you add one (up to 4).

During the game, players tap one of the colored shape pills (▲ ♦ ● ■).

### 7.2. True / False

A question with just two fixed options: **True** and **False**.

1. Write the statement.
2. Tick which option (True or False) is the correct one.

The "True"/"False" labels are fixed and can't be edited.

### 7.3. Free answer

The player **types** the answer instead of choosing from a list.

1. Write the question.
2. In **The correct answer (shown)**, write the correct answer (the first variant is the one shown to everyone at the reveal as "the correct answer").
3. Optionally, tap **Add accepted variant** to add synonyms or alternative spellings (e.g. for "Bucharest" you can also accept "Bucuresti"). You can have up to 6 accepted variants.

**How it's scored:** the app also accepts **small typos** (fuzzy matching) and **ignores diacritics, capitalization and punctuation**. For example, "pariss" will be accepted for "Paris", and "bucuresti" for "Bucharest". Very short words (≤3 letters) are required exactly, so accidental matches don't occur.

> Tip: add to the accepted variants all the plausible forms someone might type (abbreviations, forms with/without diacritics, synonyms).

### 7.4. Numeric ("guess the number")

The player types a **number**, and the score increases the closer it is to the answer.

1. Write the question.
2. In **The correct number**, put the exact value (e.g. `42`).
3. In **Accepted tolerance (±)**, put how far off an answer can be and still count as correct (e.g. `10` means anything between 32 and 52 is correct).

**How it's scored:**

- The **exact** answer gets the maximum score.
- An answer **within the range** gets points proportional to closeness: at the edge of the range it gets half, and the closer to exact, the more.
- Outside the range = 0 points.
- **Tolerance 0** means only the exact answer is accepted.
- **Decimals with a comma** (e.g. `3,5`) and negative numbers are accepted.
- In the live game, closeness is combined with the **speed** of the answer.

---

## 8. Import and export

The format is JSON, identical in both editions.

### Export

1. In the library, tap **Export** on the desired quiz.
2. A `.json` file with the quiz name is downloaded.
3. Keep it as a backup or send it to someone.

### Import

1. On the home screen, tap **Import**.
2. **Paste** the JSON content into the text box (or, where applicable, upload the file).
3. Confirm. The quiz appears in your library.

The app checks the file on import: invalid questions are ignored, and a quiz can't exceed **100 questions** (the surplus is ignored). Potentially dangerous text is neutralized automatically, so you can safely import files received from others.

> Because both editions use the same format, a quiz exported from HTML imports into `index.php` and vice versa.

---

## 9. Game modes

After you tap **Play** on a quiz, you choose the mode:

| Mode | Where | Description |
|-----|------|-----------|
| **Solo** | both editions | You play alone, against the clock. |
| **Pass & play** | both editions | Several players on the **same** device, in turn. |
| **Live** | server only | Several players on **their own devices**, in sync (Kahoot). |
| **Self-paced homework** | server only | Everyone solves at their own pace, with a code. |

At setup you also have:

- **🔀 Shuffle questions and options** — the order differs each game (see 15).
- **👥 Team mode** (Live only) — you group players into 2–4 teams (see 14).
- **The player list** (for Solo/Pass & play) — the participants' names.

---

## 10. Solo game

1. Choose a quiz and tap **Play**.
2. Choose the **Solo** mode.
3. Optionally, write your name (otherwise you appear as "You").
4. Tap **🚀 Start game**.
5. You see a **countdown**, then the first question.
6. Answer before the time runs out:
   - for Multiple choice / True-False: tap the option (or the **1–4** / **Q W E R** keys);
   - for Free answer / Numeric: **type** the answer and tap **Submit** (or **Enter**).
7. After each question you see the **reveal**: whether you answered correctly, how many points you got, the streak of correct answers, and the current score.
8. At the end you see the **podium** with your score and a little confetti animation.

The score depends on **correctness** and **speed** (fast answers get more points), plus a **streak bonus** for consecutive correct answers.

---

## 11. Pass and play

Perfect for a table of friends with a single laptop/phone.

1. Choose a quiz and tap **Play**, then the **Pass & play** mode.
2. Add the players' names with **Add player** (up to 8). With **✕** you remove a player.
3. Tap **🚀 Start game**.
4. For each question, the app announces **whose turn** it is ("Pass to..."). The current player taps **I'm ready ▶** and answers.
5. The others do **not** see whether the answer is correct until the reveal (so they can't copy).
6. After everyone has answered, the **reveal** appears with the correct answer and how much each got.
7. Between questions a **scoreboard** appears showing who's rising and who's falling.
8. At the end, the **podium**.

> Actually pass the device from one player to another when the app asks you to.

---

## 12. Live game — host

This is the "Kahoot" mode: you project the questions, and the room answers from their phones. **Requires the server edition** and the **host password**.

### Step 1 — Sign in as host

1. Open the app's address.
2. Sign in as host with the **password** received from the administrator.
   - *On the server's first use*, if there is no password yet, you'll be asked to **set** one (at least 6 characters). See also the *Administrator manual*.

### Step 2 — Creating the game session

1. Choose the quiz and start a **live game**.
2. Optionally, enable **🔀 shuffling** and/or **👥 team mode** (2–4 teams).
3. Confirm creation. The app generates a **short code** (e.g. `ABCD`).

### Step 3 — Inviting participants

On the host screen appear:

- The join **code**;
- a **QR code** the participants scan;
- (where applicable) the direct join link.

Participants open the address, enter the code (or scan the QR), and enter the **lobby**.

### Step 4 — Lobby

- You see the list of joining participants, in real time.
- If you enabled teams, you see how many members each team has.
- You wait for everyone to join, then **start** the game.

### Step 5 — Running the game

For each question:

1. The host displays the question and starts the timer.
2. Participants answer from their devices.
3. At expiry (or when everyone answers), the host moves to the **reveal**: the correct answer, the distribution of answers, the fastest players.
4. You see the updated **scoreboard**.
5. You move to the next question.

During the game, participants can send **emoji reactions** that float across the host screen (see 15).

### Step 6 — Ending

At the last question, the host displays the **podium** (top 3) and, if you played in teams, the **team standings** with the winning team highlighted. From here you can open the **reports** (section 19).

---

## 13. Live game — participant

As a participant you **don't need an account or password**.

1. Open the address given by the host (or scan the QR code).
2. Enter the game **code**.
3. Choose a **name** (you can tap **🎲** for an automatically **generated nickname**) and an **avatar** (emoji).
4. If the game is in teams, **choose your team** (colored button).
5. You enter the **lobby** and wait for the host to start.
6. For each question, **answer** on your phone before the time runs out.
7. After each question you see whether you scored and your position.
8. You can send **emoji reactions** from your screen.
9. At the end you see **your place** (and your team's, if applicable).

---

## 14. Team mode

Available in the **live game**. You group players into colored teams: **🔴 Reds, 🔵 Blues, 🟢 Greens, 🟡 Yellows**.

**As host:**

1. When setting up the live game, enable **👥 Team mode**.
2. Choose the number of teams: **2, 3 or 4**.
3. Create the game as usual.

**How it works:**

- On joining, each participant **chooses their team**.
- Everyone plays and scores **individually**, but scores are **summed by team**.
- In the lobby, the host sees the number of members per team.
- At the end, alongside the individual podium, a **team standings** appears with the winning team highlighted.
- Each player sees their team and the team's position.

---

## 15. Reactions, nicknames, shuffle

### Emoji reactions (live game)

During the game, participants have a bar with **👏 ❤️ 😮 😂 🔥 🎉**. When you tap one, the emoji **floats across the host screen**, creating atmosphere. Reactions are rate-limited so there's no spam.

### Nickname generator

When joining a live game, the **🎲** button next to the name field suggests a friendly **random nickname** (adjective + noun + number), useful if you don't want to use your real name.

### Shuffle

At setup, the **🔀 Shuffle questions and options** toggle makes it so that:

- the order of **questions** differs each game;
- the order of **options** on multiple-choice questions is shuffled.

True/False, free answer and numeric keep their structure. It works in all modes (Solo, Pass & play, Live).

> Shuffling is useful when you reuse the same quiz with different groups or when you want to discourage copying between neighbors.

---

## 16. Self-paced

The "homework" mode (self-paced, in the style of Quizizz) lets each participant solve the quiz **at their own pace**, not in sync with the host. **Requires the server edition.**

**As host / teacher:**

1. Choose the quiz and create a **self-paced homework** session.
2. Distribute the **code** to participants.

**As participant:**

1. You join with the code.
2. You go through the questions one by one, at your own pace.
3. You get feedback after each question.
4. At the end you see your result.

The scoring uses the same rules as the game (including closeness for numeric), but without the pressure of synchronous speed.

---

## 17. Audience activities

Besides quizzes, the server edition offers **live audience activities** in the style of Slido/Mentimeter. The host creates an activity, gets a code/QR, and the audience contributes from phones; the results appear **in real time** on the host screen.

Available types:

### Word cloud

The audience sends short words; the more frequent words appear larger. Good for "describe in one word...".

### Poll

The host defines options; the audience votes; you see the percentages live on colored bars.

### Q&A (questions from the audience), with moderation

The audience sends questions and can **upvote** (▲) others', so popular ones rise. The host can:

- **moderate** — if moderation is on, questions must be **approved** before they're visible to everyone;
- mark a question with a **star** (★) or as **answered** (✓);
- **hide** or **delete** questions.

### Rating / NPS

The audience gives a rating (stars on a scale of 5, or 0–10 for NPS). You see the average / NPS score and the distribution.

### Ranking

The audience orders some options by preference; you see the average ranking.

### Scales (Likert)

Several statements rated on a scale (e.g. "disagree ↔ agree"); you see the average of each statement.

### 100 points

Each participant distributes a budget of 100 points across the options; you see how the audience's "budget" is split.

> **Open / Closed:** the host can **close** an activity to stop new contributions, then reopen it.

---

## 18. Presentations (deck)

The server edition lets you build a **presentation** with several **slides**, each being a different activity (word cloud, poll, Q&A, rating, ranking, scales, 100 points).

1. You create a presentation, give it a **title**.
2. You add slides, choosing each one's type and configuring it.
3. At runtime, the host **navigates** between slides with **◀ / ▶**, and the audience always sees the current activity.

Useful for an entire interactive workshop run from a single session.

---

## 19. Reports

After a live game, the host can open **reports** with:

- results per participant (score, correct answers);
- statistics per question (how many answered, percentage correct, average time);
- for multiple-choice/true-false, the **distribution** of answers across options.

Reports can be **exported** (CSV / JSON) or **printed**.

> For free-answer and numeric questions, the report shows the general statistics (how many, correctness), without a distribution chart across options.

---

## 20. Spinner wheel

A separate tool, **fully offline**, for random choices (who answers, who wins, random teams, etc.). Available in **both editions**, via the **🎡 Wheel** button on the home screen.

1. Tap **Wheel**.
2. In the right panel, write the **items**, one per line (names, options). You can have up to 24.
3. Tap **🎯 Spin**. The wheel turns and stops on a winner.
4. Options:
   - **Remove the winner after each spin** — useful for drawing in turn, without repetition (it stops at a minimum of 2 items);
   - **🔀 Shuffle** — reorders the items;
   - **↺ Reset** — returns to the default list.

The list is remembered locally between sessions.

---

## 21. Install as an app (PWA)

The server edition can be **installed** as an app on phone or desktop (it also works offline for already-visited screens).

- **On a phone (Chrome/Android):** open the address, then the browser menu → *Add to Home screen*.
- **On desktop (Chrome/Edge):** an install icon appears in the address bar.
- **On iPhone (Safari):** the *Share* button → *Add to Home Screen*.

> PWA requires the server to be served over **HTTPS** (see the *Administrator manual*).

---

## 22. Tips and best practices

- **Test beforehand.** Before a workshop with an audience, run a trial game with 2–3 devices, to make sure the network, the join code and each question type work in your real conditions.
- **Export your quizzes.** Especially in the offline edition, back up via JSON export — don't rely only on the browser's memory.
- **Suitable time.** Set 20 seconds for normal questions, 5–10 for short ones, 60–120 for those that require thinking.
- **Free answer generously.** Add all the plausible accepted variants (synonyms, forms with/without diacritics, abbreviations).
- **Numeric with realistic tolerance.** Choose a tolerance that rewards closeness but doesn't make every answer correct.
- **Shuffling** is your friend when you reuse the same quiz with different groups.
- **Big screen for the host.** In the live game, project the host screen; participants see only the buttons on their phone.

---

## 23. Troubleshooting

**I don't see the quizzes I created.**
In the offline edition, quizzes live in that browser's memory. Check that you're using the same browser/device and that you haven't cleared the data. In the future, export them as a backup.

**I imported a file and nothing appears / fewer questions appear.**
The file may be invalid or exceed 100 questions (the surplus is ignored). Questions without text or without valid answers are skipped automatically.

**In the live game, participants can't connect.**
Check: they're on the same network / have access to the address; they entered the code correctly; the game hasn't already been closed. Ask the administrator to confirm the server is running and accessible.

**The install button (PWA) doesn't appear.**
PWA requires HTTPS. If the server is on plain HTTP, installation isn't available (but the app works normally in the browser).

**Sound doesn't work.**
Check the sound toggle in the top bar and the device's volume. Some browsers require a first interaction (a click) before playing sound.

**The free-answer field doesn't keep my text.**
Write and tap **Submit** or **Enter** before the time expires. If time runs out, the question is considered missed.

---

## 24. Privacy

- The app has **no telemetry** and **does not track** users.
- In the offline edition, **all data stays on your device** (the browser's memory and the exported files).
- In the server edition, game sessions and any quizzes are kept on the administrator's server (see the *Administrator manual* for details on storage and deletion).
- Participants in a live game **don't need an account**; they use only a name of their choice (which can also be a generated nickname).

---

*Undava — a single-file, offline-first, trade-free tool.
For installation, configuration and maintenance, see the **Administrator manual**.*
</script>
<script type="text/markdown" id="manual-user-fr">
# Undava — Manuel de l'utilisateur

*Un guide complet, étape par étape, pour créer des quiz, jouer en classe et animer des activités d'audience.*

---

## Sommaire

1. [Ce qu'est Undava](#1-ce-quest-undava)
2. [Les deux éditions et quand utiliser chacune](#2-les-deux-editions)
3. [Premiers pas](#3-premiers-pas)
4. [Visite de l'interface](#4-visite-interface)
5. [La bibliothèque de quiz](#5-bibliotheque)
6. [Créer un quiz dans l'éditeur](#6-creer-un-quiz)
7. [Les quatre types de questions](#7-types-de-questions)
8. [Import et export (JSON)](#8-import-export)
9. [Les modes de jeu](#9-modes-de-jeu)
10. [Jeu Solo — étape par étape](#10-jeu-solo)
11. [Jeu chacun son tour — étape par étape](#11-chacun-son-tour)
12. [Jeu en direct synchrone — l'animateur](#12-direct-animateur)
13. [Jeu en direct synchrone — le participant](#13-direct-participant)
14. [Mode équipes](#14-mode-equipes)
15. [Réactions, pseudos, mélange](#15-reactions-pseudos-melange)
16. [Devoir en autonomie](#16-autonomie)
17. [Activités d'audience (Slido/Mentimeter)](#17-activites-audience)
18. [Présentations (deck)](#18-presentations)
19. [Rapports après jeu](#19-rapports)
20. [La roue de la fortune](#20-roue)
21. [Installer comme appli (PWA)](#21-installer-pwa)
22. [Conseils et bonnes pratiques](#22-conseils)
23. [Dépannage pour les utilisateurs](#23-depannage)
24. [Confidentialité](#24-confidentialite)

---

## 1. Ce qu'est Undava

Undava est un jeu de quiz dans l'esprit de Kahoot, combiné à des activités d'audience à la manière de Slido/Mentimeter. Tu peux créer des quiz, y jouer seul ou en groupe (sur un seul appareil ou sur plusieurs, en synchronisation), et lancer des activités en direct (nuage de mots, sondages, questions du public).

L'application est **hors ligne d'abord**, **sans télémétrie**, **sans comptes obligatoires pour les participants** et **sans commerce**. Tes quiz t'appartiennent et sont enregistrés localement ou sous forme de fichiers JSON que tu peux déplacer partout.

---

## 2. Les deux éditions

L'application se présente sous deux formes. Toutes deux s'appellent « Undava » et utilisent le **même format JSON** pour les quiz, donc tu peux les déplacer librement entre les deux.

### `quiz-fara-frontiere.html` — l'édition entièrement hors ligne

- Un seul fichier HTML. Ouvre-le d'un double-clic, **sans installation, sans internet, sans serveur**.
- Idéale pour : préparer chez soi, jeux en solo, jeux « chacun son tour » (plusieurs joueurs à tour de rôle, sur le même appareil), en classe sans réseau.
- Contient : les **4 types de questions**, les jeux **Solo** et **Chacun son tour**, le **mélange** des questions et des options, et la **Roue de la fortune**.
- Les quiz sont enregistrés dans le stockage local du navigateur (et par export JSON).

### `index.php` — l'édition serveur (complète)

- Doit être hébergée sur un serveur avec PHP (voir le *Manuel de l'administrateur*). Elle ne s'ouvre **pas** directement d'un double-clic.
- Contient tout ce que possède l'édition hors ligne **plus** les fonctions qui nécessitent un réseau :
  - **Jeu en direct synchrone** (plusieurs joueurs, sur leurs appareils, en temps réel) ;
  - **Devoir en autonomie** (chacun résout à son rythme) ;
  - **Activités d'audience** (nuage de mots, sondage, Q&R modéré, note/NPS, classement, échelles, 100 points) ;
  - **Présentations** avec plusieurs activités sur des diapositives ;
  - **Mode équipes**, **réactions emoji**, **codes + QR d'entrée**, **rapports** après jeu, **installation comme appli (PWA)**.

> **En bref :** si tu veux juste créer et t'entraîner seul ou avec des amis sur un ordinateur portable, le fichier HTML suffit. Si tu veux un jeu en direct avec une salle pleine, il te faut l'édition serveur.

---

## 3. Premiers pas

### A. L'édition hors ligne (HTML)

1. Récupère le fichier `quiz-fara-frontiere.html`.
2. Double-clique dessus. Il s'ouvre dans ton navigateur par défaut.
3. Voilà — tu es sur l'écran d'accueil. Rien d'autre n'est nécessaire.

### B. L'édition serveur (index.php)

1. L'administrateur te donne une **adresse web** (par ex. `https://exemple.com/quiz/`).
2. Ouvre l'adresse dans un navigateur.
3. Pour **créer et animer** des jeux en direct, il te faudra le **mot de passe animateur** de l'administrateur (voir la section 12). En tant que **participant**, tu n'as pas besoin de mot de passe.

### La langue de l'interface

L'application parle 7 langues (roumain, anglais, français, italien, espagnol, portugais, allemand). Change la langue dans le coin en haut à droite. Ton choix est mémorisé.

---

## 4. Visite de l'interface

L'écran d'accueil comporte, de haut en bas :

- **La barre du haut** — le logo « Undava » (cliquer dessus te ramène à l'accueil), le sélecteur de langue et le bouton de son.
- **Le titre** et un court sous-titre.
- **Les grands boutons d'action :**
  - **Jouer** — ouvre la bibliothèque de quiz, où tu choisis à quoi jouer.
  - **Créer** — ouvre l'éditeur pour un nouveau quiz.
  - **Importer** — charge un quiz depuis un fichier JSON.
  - **Roue** — ouvre la Roue de la fortune (sélecteur aléatoire).
- **Caractéristiques** affichées comme badges : hors ligne, gratuit, privé, ouvert.

> Sur téléphone, les boutons se disposent verticalement et tout le contenu s'adapte au petit écran.

---

## 5. La bibliothèque de quiz

Tu appuies sur **Jouer** et tu arrives dans la bibliothèque. Ici tu vois :

- **Des quiz d'exemple** (marqués « DÉMO ») — des exemples prêts à l'emploi pour tester tout de suite l'application.
- **Tes quiz** (marqués « À TOI ») — ceux que tu as créés ou importés.

Chaque quiz apparaît comme une carte colorée montrant le titre, la description et le **nombre de questions**. Sur chaque carte tu as des options pour :

- **Jouer** — lance la configuration du jeu (tu choisis le mode).
- **Modifier** — ouvre le quiz dans l'éditeur.
- **Exporter** — enregistre le quiz comme fichier JSON.
- **Supprimer** — retire le quiz (seulement pour tes quiz ; les démos ne peuvent pas être supprimées).

> Un quiz d'exemple ne se modifie pas directement : si tu appuies sur Modifier sur une démo, l'application te fait une **copie** modifiable (marquée « ✎ »), pour ne pas abîmer l'original.

---

## 6. Créer un quiz

Tu appuies sur **Créer** (ou **Modifier** sur un quiz existant). L'éditeur a deux zones : l'en-tête du quiz et la liste des questions.

### Étape 1 — Titre et description

1. Dans le champ **Titre**, écris le nom du quiz (obligatoire, jusqu'à 80–200 caractères).
2. Dans le champ **Description**, écris éventuellement un court résumé (ce que couvre le quiz).

### Étape 2 — Ajouter des questions

- Un nouveau quiz démarre avec une question vide.
- Appuie sur **＋ Ajouter une question** pour en ajouter d'autres.
- Tu peux avoir **jusqu'à 100 questions** dans un quiz.

### Étape 3 — Configurer chaque question

Chaque question a, dans son en-tête :

- Un **sélecteur de type** (Choix multiple / Vrai-Faux / Réponse libre / Numérique) — voir la section 7.
- Un **sélecteur de temps** — combien de temps les joueurs ont pour répondre : **5, 10, 20, 30, 60 ou 120 secondes**.
- Un **sélecteur de points** — **Standard (1000)** ou **Double (2000)** pour les questions plus difficiles.
- Des boutons **↑ / ↓** pour réordonner la question dans la liste.
- Le bouton **🗑** pour supprimer la question.

Dans le corps de la question tu écris le **texte de la question** (jusqu'à 200–500 caractères) et tu configures les réponses selon le type.

### Étape 4 — Enregistrer

Tu appuies sur **✓ Enregistrer**. L'application vérifie d'abord :

- que le quiz a un titre ;
- que chaque question a un texte ;
- que chaque question a ses réponses correctement remplies pour son type (voir plus bas).

S'il manque quelque chose, tu reçois un message clair qui te dit quelle question corriger. Après l'enregistrement, le quiz apparaît dans la bibliothèque sous « À TOI ».

> **Note hors ligne :** dans l'édition HTML, les quiz sont enregistrés dans le stockage local du navigateur. Si tu vides les données du navigateur ou utilises un autre navigateur/appareil, tu ne les vois plus — c'est pourquoi il est important de les **exporter** (section 8) comme sauvegarde.

---

## 7. Les quatre types de questions

Tu choisis le type dans le sélecteur de l'en-tête de la question.

### 7.1. Choix multiple (4 options)

La question classique à options multiples.

1. Écris le texte de la question.
2. Remplis les options de réponse (**2 à 4**).
3. Coche **Correct** à côté de la bonne option (exactement une).
4. Avec **✕** tu supprimes une option ; avec **Ajouter une réponse** tu en ajoutes une (jusqu'à 4).

Pendant le jeu, les joueurs appuient sur l'une des pastilles colorées à formes (▲ ♦ ● ■).

### 7.2. Vrai / Faux

Une question avec seulement deux options fixes : **Vrai** et **Faux**.

1. Écris l'affirmation.
2. Coche quelle option (Vrai ou Faux) est la bonne.

Les étiquettes « Vrai »/« Faux » sont fixes et ne se modifient pas.

### 7.3. Réponse libre

Le joueur **écrit** la réponse au lieu de choisir dans une liste.

1. Écris la question.
2. Dans **La réponse correcte (affichée)**, écris la bonne réponse (la première variante est celle montrée à tous à la révélation comme « la bonne réponse »).
3. Éventuellement, appuie sur **Ajouter une variante acceptée** pour ajouter des synonymes ou des orthographes alternatives (par ex. pour « Bucarest » tu peux aussi accepter « Bucuresti »). Tu peux avoir jusqu'à 6 variantes acceptées.

**Comment c'est noté :** l'application accepte aussi les **petites fautes de frappe** (correspondance approximative) et **ignore les accents, les majuscules et la ponctuation**. Par exemple, « pariss » sera accepté pour « Paris ». Les mots très courts (≤3 lettres) sont exigés exactement, pour éviter les correspondances accidentelles.

> Astuce : ajoute aux variantes acceptées toutes les formes plausibles que quelqu'un pourrait écrire (abréviations, formes avec/sans accents, synonymes).

### 7.4. Numérique (« devine le nombre »)

Le joueur écrit un **nombre**, et le score augmente à mesure qu'il se rapproche de la réponse.

1. Écris la question.
2. Dans **Le nombre correct**, mets la valeur exacte (par ex. `42`).
3. Dans **Tolérance acceptée (±)**, mets à quelle distance une réponse peut être et compter quand même comme correcte (par ex. `10` signifie que tout entre 32 et 52 est correct).

**Comment c'est noté :**

- La réponse **exacte** obtient le score maximum.
- Une réponse **dans l'intervalle** obtient des points proportionnels à la proximité : au bord de l'intervalle elle obtient la moitié, et plus elle est proche de l'exact, plus elle obtient.
- Hors de l'intervalle = 0 point.
- **Tolérance 0** signifie que seule la réponse exacte est acceptée.
- Les **décimales avec une virgule** (par ex. `3,5`) et les nombres négatifs sont acceptés.
- Dans le jeu en direct, la proximité se combine avec la **vitesse** de la réponse.

---

## 8. Import et export

Le format est JSON, identique dans les deux éditions.

### Export

1. Dans la bibliothèque, appuie sur **Exporter** sur le quiz souhaité.
2. Un fichier `.json` portant le nom du quiz est téléchargé.
3. Garde-le comme sauvegarde ou envoie-le à quelqu'un.

### Import

1. Sur l'écran d'accueil, appuie sur **Importer**.
2. **Colle** le contenu JSON dans la zone de texte (ou, le cas échéant, charge le fichier).
3. Confirme. Le quiz apparaît dans ta bibliothèque.

L'application vérifie le fichier à l'import : les questions invalides sont ignorées, et un quiz ne peut pas dépasser **100 questions** (le surplus est ignoré). Le texte potentiellement dangereux est neutralisé automatiquement, donc tu peux importer en toute sécurité des fichiers reçus d'autres personnes.

> Comme les deux éditions utilisent le même format, un quiz exporté depuis HTML s'importe dans `index.php` et vice versa.

---

## 9. Les modes de jeu

Après avoir appuyé sur **Jouer** sur un quiz, tu choisis le mode :

| Mode | Où | Description |
|-----|------|-----------|
| **Solo** | les deux éditions | Tu joues seul, contre la montre. |
| **Chacun son tour** | les deux éditions | Plusieurs joueurs sur le **même** appareil, à tour de rôle. |
| **En direct** | serveur seulement | Plusieurs joueurs sur **leurs appareils**, en synchro (Kahoot). |
| **Devoir en autonomie** | serveur seulement | Chacun résout à son rythme, avec un code. |

À la configuration tu as aussi :

- **🔀 Mélanger les questions et les options** — l'ordre diffère à chaque jeu (voir 15).
- **👥 Mode équipes** (en direct seulement) — tu regroupes les joueurs en 2–4 équipes (voir 14).
- **La liste des joueurs** (en Solo/Chacun son tour) — les noms des participants.

---

## 10. Jeu Solo

1. Choisis un quiz et appuie sur **Jouer**.
2. Choisis le mode **Solo**.
3. Éventuellement, écris ton nom (sinon tu apparais comme « Toi »).
4. Appuie sur **🚀 Commencer**.
5. Tu vois un **compte à rebours**, puis la première question.
6. Réponds avant la fin du temps :
   - en Choix multiple / Vrai-Faux : appuie sur l'option (ou les touches **1–4** / **Q W E R**) ;
   - en Réponse libre / Numérique : **écris** la réponse et appuie sur **Envoyer** (ou **Entrée**).
7. Après chaque question tu vois la **révélation** : si tu as répondu correctement, combien de points tu as obtenus, la série de bonnes réponses et le score actuel.
8. À la fin tu vois le **podium** avec ton score et une petite animation de confettis.

Le score dépend de la **justesse** et de la **vitesse** (les réponses rapides obtiennent plus de points), plus un **bonus de série** pour les bonnes réponses consécutives.

---

## 11. Chacun son tour

Parfait pour une tablée d'amis avec un seul ordinateur/téléphone.

1. Choisis un quiz et appuie sur **Jouer**, puis le mode **Chacun son tour**.
2. Ajoute les noms des joueurs avec **Ajouter un joueur** (jusqu'à 8). Avec **✕** tu retires un joueur.
3. Appuie sur **🚀 Commencer**.
4. Pour chaque question, l'application annonce **à qui c'est le tour** (« Passe à... »). Le joueur actuel appuie sur **Je suis prêt ▶** et répond.
5. Les autres ne voient **pas** si la réponse est correcte avant la révélation (pour ne pas copier).
6. Une fois que tous ont répondu, la **révélation** apparaît avec la bonne réponse et ce que chacun a obtenu.
7. Entre les questions apparaît un **classement** qui montre qui monte et qui descend.
8. À la fin, le **podium**.

> Passe effectivement l'appareil d'un joueur à l'autre quand l'application te le demande.

---

## 12. Jeu en direct — l'animateur

C'est le mode « Kahoot » : tu projettes les questions, et la salle répond depuis les téléphones. **Nécessite l'édition serveur** et le **mot de passe animateur**.

### Étape 1 — Se connecter comme animateur

1. Ouvre l'adresse de l'application.
2. Connecte-toi comme animateur avec le **mot de passe** reçu de l'administrateur.
   - *À la première utilisation du serveur*, s'il n'y a pas encore de mot de passe, on te demandera d'en **définir** un (au moins 6 caractères). Voir aussi le *Manuel de l'administrateur*.

### Étape 2 — Créer la session de jeu

1. Choisis le quiz et démarre un **jeu en direct**.
2. Éventuellement, active le **🔀 mélange** et/ou le **👥 mode équipes** (2–4 équipes).
3. Confirme la création. L'application génère un **code court** (par ex. `ABCD`).

### Étape 3 — Inviter les participants

Sur l'écran de l'animateur apparaissent :

- Le **code** d'entrée ;
- un **code QR** que les participants scannent ;
- (le cas échéant) le lien d'entrée direct.

Les participants ouvrent l'adresse, saisissent le code (ou scannent le QR) et entrent dans le **salon**.

### Étape 4 — Salon

- Tu vois la liste des participants qui rejoignent, en temps réel.
- Si tu as activé les équipes, tu vois combien de membres a chaque équipe.
- Tu attends que tous rejoignent, puis tu **démarres** le jeu.

### Étape 5 — Déroulement du jeu

Pour chaque question :

1. L'animateur affiche la question et démarre le chronomètre.
2. Les participants répondent depuis leurs appareils.
3. À l'expiration (ou quand tous répondent), l'animateur passe à la **révélation** : la bonne réponse, la répartition des réponses, les plus rapides.
4. Tu vois le **classement** mis à jour.
5. Tu passes à la question suivante.

Pendant le jeu, les participants peuvent envoyer des **réactions emoji** qui flottent sur l'écran de l'animateur (voir 15).

### Étape 6 — Fin

À la dernière question, l'animateur affiche le **podium** (top 3) et, si tu as joué en équipes, le **classement des équipes** avec l'équipe gagnante mise en avant. De là tu peux ouvrir les **rapports** (section 19).

---

## 13. Jeu en direct — le participant

En tant que participant, tu **n'as pas besoin de compte ni de mot de passe**.

1. Ouvre l'adresse donnée par l'animateur (ou scanne le code QR).
2. Saisis le **code** du jeu.
3. Choisis un **nom** (tu peux appuyer sur **🎲** pour un **pseudo généré** automatiquement) et un **avatar** (emoji).
4. Si le jeu est en équipes, **choisis ton équipe** (bouton coloré).
5. Tu entres dans le **salon** et tu attends que l'animateur démarre.
6. Pour chaque question, **réponds** sur ton téléphone avant la fin du temps.
7. Après chaque question tu vois si tu as marqué et ta place.
8. Tu peux envoyer des **réactions emoji** depuis ton écran.
9. À la fin tu vois **ta place** (et celle de ton équipe, le cas échéant).

---

## 14. Mode équipes

Disponible dans le **jeu en direct**. Tu regroupes les joueurs en équipes colorées : **🔴 Rouges, 🔵 Bleus, 🟢 Verts, 🟡 Jaunes**.

**En tant qu'animateur :**

1. À la configuration du jeu en direct, active le **👥 Mode équipes**.
2. Choisis le nombre d'équipes : **2, 3 ou 4**.
3. Crée le jeu comme d'habitude.

**Comment ça marche :**

- À l'entrée, chaque participant **choisit son équipe**.
- Chacun joue et marque **individuellement**, mais les scores sont **cumulés par équipe**.
- Dans le salon, l'animateur voit le nombre de membres par équipe.
- À la fin, en plus du podium individuel, un **classement des équipes** apparaît avec l'équipe gagnante mise en avant.
- Chaque joueur voit son équipe et la place de l'équipe.

---

## 15. Réactions, pseudos, mélange

### Réactions emoji (jeu en direct)

Pendant le jeu, les participants ont une barre avec **👏 ❤️ 😮 😂 🔥 🎉**. Quand tu appuies sur l'une, l'emoji **flotte sur l'écran de l'animateur**, créant de l'ambiance. Les réactions sont limitées en cadence pour éviter le spam.

### Générateur de pseudos

À l'entrée dans un jeu en direct, le bouton **🎲** à côté du champ de nom te propose un **pseudo aléatoire** amical (adjectif + nom + numéro), utile si tu ne veux pas utiliser ton vrai nom.

### Mélange (shuffle)

À la configuration, le bouton **🔀 Mélanger les questions et les options** fait que :

- l'ordre des **questions** diffère à chaque jeu ;
- l'ordre des **options** aux questions à choix multiple est mélangé.

Vrai/Faux, la réponse libre et le numérique gardent leur structure. Cela fonctionne dans tous les modes (Solo, Chacun son tour, En direct).

> Le mélange est utile quand tu réutilises le même quiz avec des groupes différents ou quand tu veux décourager la copie entre voisins.

---

## 16. Devoir en autonomie

Le mode « devoir » (à son rythme, à la manière de Quizizz) permet à chaque participant de résoudre le quiz **à son propre rythme**, pas en synchro avec l'animateur. **Nécessite l'édition serveur.**

**En tant qu'animateur / enseignant :**

1. Choisis le quiz et crée une session de type **devoir en autonomie**.
2. Distribue le **code** aux participants.

**En tant que participant :**

1. Tu entres avec le code.
2. Tu parcours les questions une à une, à ton rythme.
3. Tu reçois un retour après chaque question.
4. À la fin tu vois ton résultat.

La notation utilise les mêmes règles que le jeu (y compris la proximité pour le numérique), mais sans la pression de la vitesse synchrone.

---

## 17. Activités d'audience

En plus des quiz, l'édition serveur offre des **activités d'audience en direct** à la manière de Slido/Mentimeter. L'animateur crée une activité, reçoit un code/QR, et le public contribue depuis les téléphones ; les résultats apparaissent **en temps réel** sur l'écran de l'animateur.

Types disponibles :

### Nuage de mots

Le public envoie des mots courts ; les mots plus fréquents apparaissent plus grands. Bon pour « décrivez en un mot... ».

### Sondage

L'animateur définit des options ; le public vote ; tu vois les pourcentages en direct sur des barres colorées.

### Q&R (questions du public), avec modération

Le public envoie des questions et peut **voter** (▲) pour celles des autres, pour que les populaires montent. L'animateur peut :

- **modérer** — si la modération est active, les questions doivent être **approuvées** avant d'être visibles par tous ;
- marquer une question d'une **étoile** (★) ou comme **répondue** (✓) ;
- **masquer** ou **supprimer** des questions.

### Note / NPS

Le public donne une note (étoiles sur une échelle de 5, ou 0–10 pour le NPS). Tu vois la moyenne / le score NPS et la distribution.

### Classement

Le public ordonne des options par préférence ; tu vois le classement moyen.

### Échelles (Likert)

Plusieurs affirmations évaluées sur une échelle (par ex. « désaccord ↔ accord ») ; tu vois la moyenne de chaque affirmation.

### 100 points

Chaque participant répartit un budget de 100 points entre les options ; tu vois comment le « budget » de l'audience se répartit.

> **Ouvert / Fermé :** l'animateur peut **fermer** une activité pour arrêter les nouvelles contributions, puis la rouvrir.

---

## 18. Présentations (deck)

L'édition serveur permet de construire une **présentation** avec plusieurs **diapositives**, chacune étant une activité différente (nuage de mots, sondage, Q&R, note, classement, échelles, 100 points).

1. Tu crées une présentation, tu lui donnes un **titre**.
2. Tu ajoutes des diapositives, en choisissant le type de chacune et en la configurant.
3. À l'exécution, l'animateur **navigue** entre les diapositives avec **◀ / ▶**, et le public voit toujours l'activité en cours.

Utile pour tout un atelier interactif mené depuis une seule session.

---

## 19. Rapports après jeu

Après un jeu en direct, l'animateur peut ouvrir des **rapports** avec :

- les résultats par participant (score, bonnes réponses) ;
- les statistiques par question (combien ont répondu, pourcentage de justesse, temps moyen) ;
- pour le choix multiple/vrai-faux, la **répartition** des réponses entre les options.

Les rapports peuvent être **exportés** (CSV / JSON) ou **imprimés**.

> Pour les questions à réponse libre et numériques, le rapport montre les statistiques générales (combien, justesse), sans graphique de répartition entre les options.

---

## 20. La roue de la fortune

Un outil séparé, **entièrement hors ligne**, pour les choix aléatoires (qui répond, qui gagne, équipes au hasard, etc.). Disponible dans les **deux éditions**, via le bouton **🎡 Roue** sur l'écran d'accueil.

1. Appuie sur **Roue**.
2. Dans le panneau de droite, écris les **éléments**, un par ligne (noms, options). Tu peux en avoir jusqu'à 24.
3. Appuie sur **🎯 Tourner**. La roue tourne et s'arrête sur un gagnant.
4. Options :
   - **Retirer le gagnant après chaque tirage** — utile pour tirer à tour de rôle, sans répétition (s'arrête à un minimum de 2 éléments) ;
   - **🔀 Mélanger** — réordonne les éléments ;
   - **↺ Réinitialiser** — revient à la liste par défaut.

La liste est mémorisée localement entre les sessions.

---

## 21. Installer comme appli (PWA)

L'édition serveur peut être **installée** comme appli sur téléphone ou ordinateur (elle fonctionne aussi hors ligne pour les écrans déjà visités).

- **Sur téléphone (Chrome/Android) :** ouvre l'adresse, puis le menu du navigateur → *Ajouter à l'écran d'accueil*.
- **Sur ordinateur (Chrome/Edge) :** une icône d'installation apparaît dans la barre d'adresse.
- **Sur iPhone (Safari) :** le bouton *Partager* → *Sur l'écran d'accueil*.

> Le PWA nécessite que le serveur soit servi via **HTTPS** (voir le *Manuel de l'administrateur*).

---

## 22. Conseils et bonnes pratiques

- **Teste avant.** Avant un atelier avec public, lance un jeu d'essai avec 2–3 appareils, pour t'assurer que le réseau, le code d'entrée et chaque type de question fonctionnent dans tes conditions réelles.
- **Exporte tes quiz.** Surtout dans l'édition hors ligne, fais une sauvegarde par export JSON — ne te fie pas seulement à la mémoire du navigateur.
- **Temps adapté.** Mets 20 secondes pour les questions normales, 5–10 pour les courtes, 60–120 pour celles qui demandent de la réflexion.
- **Réponse libre avec générosité.** Ajoute toutes les variantes acceptées plausibles (synonymes, formes avec/sans accents, abréviations).
- **Numérique avec une tolérance réaliste.** Choisis une tolérance qui récompense la proximité mais ne rend pas toute réponse correcte.
- **Le mélange** est ton ami quand tu réutilises le même quiz avec des groupes différents.
- **Grand écran pour l'animateur.** Dans le jeu en direct, projette l'écran de l'animateur ; les participants ne voient que les boutons sur leur téléphone.

---

## 23. Dépannage

**Je ne vois pas les quiz que j'ai créés.**
Dans l'édition hors ligne, les quiz se trouvent dans la mémoire de ce navigateur. Vérifie que tu utilises le même navigateur/appareil et que tu n'as pas vidé les données. À l'avenir, exporte-les comme sauvegarde.

**J'ai importé un fichier et rien n'apparaît / moins de questions apparaissent.**
Le fichier peut être invalide ou dépasser 100 questions (le surplus est ignoré). Les questions sans texte ou sans réponses valides sont sautées automatiquement.

**Dans le jeu en direct, les participants ne peuvent pas se connecter.**
Vérifie : ils sont sur le même réseau / ont accès à l'adresse ; ils ont saisi le code correctement ; le jeu n'a pas déjà été fermé. Demande à l'administrateur de confirmer que le serveur est démarré et accessible.

**Le bouton d'installation (PWA) n'apparaît pas.**
Le PWA nécessite HTTPS. Si le serveur est en HTTP simple, l'installation n'est pas disponible (mais l'application fonctionne normalement dans le navigateur).

**Le son ne marche pas.**
Vérifie le bouton de son dans la barre du haut et le volume de l'appareil. Certains navigateurs exigent une première interaction (un clic) avant de jouer du son.

**Le champ de réponse libre ne garde pas mon texte.**
Écris et appuie sur **Envoyer** ou **Entrée** avant l'expiration du temps. Si le temps se termine, la question est considérée comme manquée.

---

## 24. Confidentialité

- L'application **n'a pas de télémétrie** et **ne suit pas** les utilisateurs.
- Dans l'édition hors ligne, **toutes les données restent sur ton appareil** (la mémoire du navigateur et les fichiers exportés).
- Dans l'édition serveur, les sessions de jeu et les éventuels quiz sont conservés sur le serveur de l'administrateur (voir le *Manuel de l'administrateur* pour les détails sur le stockage et la suppression).
- Les participants à un jeu en direct **n'ont pas besoin de compte** ; ils utilisent seulement un nom de leur choix (qui peut aussi être un pseudo généré).

---

*Undava — un outil à fichier unique, hors ligne d'abord, sans commerce.
Pour l'installation, la configuration et la maintenance, voir le **Manuel de l'administrateur**.*
</script>
<script type="text/markdown" id="manual-user-it">
# Undava — Manuale utente

*Una guida completa, passo dopo passo, per creare quiz, giocare in classe e gestire attività per il pubblico.*

---

## Indice

1. [Cos'è Undava](#1-cos-e-undava)
2. [Le due edizioni e quando usare ciascuna](#2-le-due-edizioni)
3. [Primi passi](#3-primi-passi)
4. [Visita dell'interfaccia](#4-visita-interfaccia)
5. [La libreria di quiz](#5-libreria)
6. [Creare un quiz nell'editor](#6-creare-un-quiz)
7. [I quattro tipi di domande](#7-tipi-di-domande)
8. [Importazione ed esportazione (JSON)](#8-importazione-esportazione)
9. [Le modalità di gioco](#9-modalita-di-gioco)
10. [Gioco Solo — passo dopo passo](#10-gioco-solo)
11. [Gioco a turni — passo dopo passo](#11-gioco-a-turni)
12. [Gioco dal vivo sincrono — il conduttore](#12-dal-vivo-conduttore)
13. [Gioco dal vivo sincrono — il partecipante](#13-dal-vivo-partecipante)
14. [Modalità a squadre](#14-modalita-squadre)
15. [Reazioni, soprannomi, mescolamento](#15-reazioni-soprannomi-mescola)
16. [Compito autonomo](#16-autonomo)
17. [Attività per il pubblico (Slido/Mentimeter)](#17-attivita-pubblico)
18. [Presentazioni (deck)](#18-presentazioni)
19. [Rapporti dopo il gioco](#19-rapporti)
20. [La ruota della fortuna](#20-ruota)
21. [Installare come app (PWA)](#21-installare-pwa)
22. [Consigli e buone pratiche](#22-consigli)
23. [Risoluzione dei problemi per gli utenti](#23-risoluzione-problemi)
24. [Riservatezza](#24-riservatezza)

---

## 1. Cos'è Undava

Undava è un gioco di quiz nello spirito di Kahoot, combinato con attività per il pubblico nello stile di Slido/Mentimeter. Puoi creare quiz, giocarli da solo o con un gruppo (su un singolo dispositivo o su molti, in sincronia), e avviare attività dal vivo (nuvola di parole, sondaggi, domande dal pubblico).

L'applicazione è **offline-first**, **senza telemetria**, **senza account obbligatori per i partecipanti** e **trade-free**. I tuoi quiz ti appartengono e vengono salvati localmente o come file JSON che puoi spostare ovunque.

---

## 2. Le due edizioni

L'applicazione arriva in due forme. Entrambe si chiamano «Undava» e usano lo **stesso formato JSON** per i quiz, quindi puoi spostarli liberamente tra le due.

### `quiz-fara-frontiere.html` — l'edizione completamente offline

- Un unico file HTML. Aprilo con un doppio clic, **senza installazione, senza internet, senza server**.
- Ideale per: preparare a casa, giochi in solitaria, giochi «a turni» (più giocatori a rotazione, sullo stesso dispositivo), in classe senza rete.
- Contiene: i **4 tipi di domande**, i giochi **Solo** e **A turni**, il **mescolamento** delle domande e delle opzioni, e la **Ruota della fortuna**.
- I quiz vengono salvati nella memoria locale del browser (e tramite esportazione JSON).

### `index.php` — l'edizione server (completa)

- Deve essere ospitata su un server con PHP (vedi il *Manuale dell'amministratore*). **Non** si apre direttamente con un doppio clic.
- Contiene tutto ciò che ha l'edizione offline **più** le funzioni che richiedono una rete:
  - **Gioco dal vivo sincrono** (più giocatori, sui loro dispositivi, in tempo reale);
  - **Compito autonomo** (ognuno risolve al proprio ritmo);
  - **Attività per il pubblico** (nuvola di parole, sondaggio, Q&A moderato, valutazione/NPS, classifica, scale, 100 punti);
  - **Presentazioni** con più attività su diapositive;
  - **Modalità a squadre**, **reazioni emoji**, **codici + QR d'ingresso**, **rapporti** dopo il gioco, **installazione come app (PWA)**.

> **In breve:** se vuoi solo creare ed esercitarti da solo o con gli amici su un portatile, basta il file HTML. Se vuoi un gioco dal vivo con la sala piena, ti serve l'edizione server.

---

## 3. Primi passi

### A. L'edizione offline (HTML)

1. Procurati il file `quiz-fara-frontiere.html`.
2. Fai doppio clic su di esso. Si apre nel tuo browser predefinito.
3. Fatto — sei sulla schermata iniziale. Non serve altro.

### B. L'edizione server (index.php)

1. L'amministratore ti dà un **indirizzo web** (ad es. `https://esempio.com/quiz/`).
2. Apri l'indirizzo nel browser.
3. Per **creare e ospitare** giochi dal vivo, ti servirà la **password del conduttore** dall'amministratore (vedi la sezione 12). Come **partecipante**, non ti serve una password.

### La lingua dell'interfaccia

L'applicazione parla 7 lingue (rumeno, inglese, francese, italiano, spagnolo, portoghese, tedesco). Cambia la lingua nell'angolo in alto a destra. La tua scelta viene ricordata.

---

## 4. Visita dell'interfaccia

La schermata iniziale ha, dall'alto in basso:

- **La barra superiore** — il logo «Undava» (cliccarci ti riporta alla Home), il selettore di lingua e l'interruttore del suono.
- **Il titolo** e un breve sottotitolo.
- **I grandi pulsanti d'azione:**
  - **Gioca** — apre la libreria di quiz, dove scegli cosa giocare.
  - **Crea** — apre l'editor per un nuovo quiz.
  - **Importa** — carichi un quiz da un file JSON.
  - **Ruota** — apre la Ruota della fortuna (selettore casuale).
- **Caratteristiche** mostrate come badge: offline, gratuito, privato, aperto.

> Su telefono, i pulsanti si dispongono in verticale e tutto il contenuto si adatta allo schermo piccolo.

---

## 5. La libreria di quiz

Premi **Gioca** e arrivi nella libreria. Qui vedi:

- **Quiz dimostrativi** (contrassegnati «DEMO») — esempi già pronti con cui puoi provare subito l'applicazione.
- **I tuoi quiz** (contrassegnati «TUO») — quelli creati o importati da te.

Ogni quiz appare come una scheda colorata che mostra il titolo, la descrizione e il **numero di domande**. Su ogni scheda hai opzioni per:

- **Gioca** — avvia la configurazione del gioco (scegli la modalità).
- **Modifica** — apre il quiz nell'editor.
- **Esporta** — salva il quiz come file JSON.
- **Elimina** — rimuove il quiz (solo per i tuoi quiz; le demo non si possono eliminare).

> Un quiz dimostrativo non si modifica direttamente: se premi Modifica su una demo, l'applicazione ti fa una **copia** modificabile (contrassegnata «✎»), per non rovinare l'originale.

---

## 6. Creare un quiz

Premi **Crea** (o **Modifica** su un quiz esistente). L'editor ha due zone: l'intestazione del quiz e l'elenco delle domande.

### Passo 1 — Titolo e descrizione

1. Nel campo **Titolo**, scrivi il nome del quiz (obbligatorio, fino a 80–200 caratteri).
2. Nel campo **Descrizione**, scrivi facoltativamente un breve riassunto (cosa copre il quiz).

### Passo 2 — Aggiungere domande

- Un nuovo quiz parte con una domanda vuota.
- Premi **＋ Aggiungi domanda** per aggiungerne altre.
- Puoi avere **fino a 100 domande** in un quiz.

### Passo 3 — Configurare ogni domanda

Ogni domanda ha, nella sua intestazione:

- Un **selettore di tipo** (Scelta multipla / Vero-Falso / Risposta libera / Numerico) — vedi la sezione 7.
- Un **selettore di tempo** — quanto tempo hanno i giocatori per rispondere: **5, 10, 20, 30, 60 o 120 secondi**.
- Un **selettore di punti** — **Standard (1000)** o **Doppio (2000)** per le domande più difficili.
- Pulsanti **↑ / ↓** per riordinare la domanda nell'elenco.
- Il pulsante **🗑** per eliminare la domanda.

Nel corpo della domanda scrivi il **testo della domanda** (fino a 200–500 caratteri) e configuri le risposte in base al tipo.

### Passo 4 — Salvare

Premi **✓ Salva**. L'applicazione verifica prima:

- che il quiz abbia un titolo;
- che ogni domanda abbia un testo;
- che ogni domanda abbia le risposte compilate correttamente per il suo tipo (vedi sotto).

Se manca qualcosa, ricevi un messaggio chiaro che ti dice quale domanda correggere. Dopo il salvataggio, il quiz appare nella libreria sotto «TUO».

> **Nota offline:** nell'edizione HTML, i quiz vengono salvati nella memoria locale del browser. Se svuoti i dati del browser o usi un altro browser/dispositivo, non li vedi più — per questo è importante **esportarli** (sezione 8) come backup.

---

## 7. I quattro tipi di domande

Scegli il tipo dal selettore nell'intestazione della domanda.

### 7.1. Scelta multipla (4 opzioni)

La classica domanda a opzioni multiple.

1. Scrivi il testo della domanda.
2. Compila le opzioni di risposta (**da 2 a 4**).
3. Spunta **Corretto** accanto all'opzione giusta (esattamente una).
4. Con **✕** rimuovi un'opzione; con **Aggiungi risposta** ne aggiungi una (fino a 4).

Durante il gioco, i giocatori premono una delle pastiglie colorate con forme (▲ ♦ ● ■).

### 7.2. Vero / Falso

Una domanda con solo due opzioni fisse: **Vero** e **Falso**.

1. Scrivi l'affermazione.
2. Spunta quale opzione (Vero o Falso) è quella corretta.

Le etichette «Vero»/«Falso» sono fisse e non si modificano.

### 7.3. Risposta libera

Il giocatore **scrive** la risposta invece di sceglierla da un elenco.

1. Scrivi la domanda.
2. In **La risposta corretta (mostrata)**, scrivi la risposta giusta (la prima variante è quella mostrata a tutti alla rivelazione come «la risposta corretta»).
3. Facoltativamente, premi **Aggiungi variante accettata** per aggiungere sinonimi o grafie alternative (ad es. per «Bucarest» puoi accettare anche «Bucuresti»). Puoi avere fino a 6 varianti accettate.

**Come si assegnano i punti:** l'applicazione accetta anche **piccoli errori di battitura** (corrispondenza approssimativa) e **ignora i segni diacritici, le maiuscole e la punteggiatura**. Ad esempio, «pariss» sarà accettato per «Paris». Le parole molto brevi (≤3 lettere) sono richieste esattamente, per evitare corrispondenze accidentali.

> Consiglio: aggiungi alle varianti accettate tutte le forme plausibili che qualcuno potrebbe scrivere (abbreviazioni, forme con/senza diacritici, sinonimi).

### 7.4. Numerico («indovina il numero»)

Il giocatore scrive un **numero**, e il punteggio aumenta quanto più è vicino alla risposta.

1. Scrivi la domanda.
2. In **Il numero corretto**, metti il valore esatto (ad es. `42`).
3. In **Tolleranza accettata (±)**, metti quanto lontana può essere una risposta e contare comunque come corretta (ad es. `10` significa che qualsiasi valore tra 32 e 52 è corretto).

**Come si assegnano i punti:**

- La risposta **esatta** ottiene il punteggio massimo.
- Una risposta **entro l'intervallo** ottiene punti proporzionali alla vicinanza: al bordo dell'intervallo ottiene la metà, e più è vicina all'esatto, più ottiene.
- Fuori dall'intervallo = 0 punti.
- **Tolleranza 0** significa che si accetta solo la risposta esatta.
- Si accettano i **decimali con la virgola** (ad es. `3,5`) e i numeri negativi.
- Nel gioco dal vivo, la vicinanza si combina con la **velocità** della risposta.

---

## 8. Importazione ed esportazione

Il formato è JSON, identico in entrambe le edizioni.

### Esportazione

1. Nella libreria, premi **Esporta** sul quiz desiderato.
2. Viene scaricato un file `.json` con il nome del quiz.
3. Conservalo come backup o invialo a qualcuno.

### Importazione

1. Nella schermata iniziale, premi **Importa**.
2. **Incolla** il contenuto JSON nella casella di testo (o, se necessario, carica il file).
3. Conferma. Il quiz appare nella tua libreria.

L'applicazione verifica il file all'importazione: le domande non valide vengono ignorate, e un quiz non può superare le **100 domande** (l'eccedenza viene ignorata). Il testo potenzialmente pericoloso viene neutralizzato automaticamente, quindi puoi importare in sicurezza file ricevuti da altri.

> Poiché entrambe le edizioni usano lo stesso formato, un quiz esportato da HTML si importa in `index.php` e viceversa.

---

## 9. Le modalità di gioco

Dopo aver premuto **Gioca** su un quiz, scegli la modalità:

| Modalità | Dove | Descrizione |
|-----|------|-----------|
| **Solo** | entrambe le edizioni | Giochi da solo, contro il tempo. |
| **A turni** | entrambe le edizioni | Più giocatori sullo **stesso** dispositivo, a rotazione. |
| **Dal vivo** | solo server | Più giocatori sui **loro dispositivi**, in sincronia (Kahoot). |
| **Compito autonomo** | solo server | Ognuno risolve al proprio ritmo, con un codice. |

Alla configurazione hai anche:

- **🔀 Mescola domande e opzioni** — l'ordine differisce a ogni gioco (vedi 15).
- **👥 Modalità a squadre** (solo Dal vivo) — raggruppi i giocatori in 2–4 squadre (vedi 14).
- **L'elenco dei giocatori** (in Solo/A turni) — i nomi dei partecipanti.

---

## 10. Gioco Solo

1. Scegli un quiz e premi **Gioca**.
2. Scegli la modalità **Solo**.
3. Facoltativamente, scrivi il tuo nome (altrimenti appari come «Tu»).
4. Premi **🚀 Inizia il gioco**.
5. Vedi un **conto alla rovescia**, poi la prima domanda.
6. Rispondi prima che scada il tempo:
   - in Scelta multipla / Vero-Falso: premi l'opzione (o i tasti **1–4** / **Q W E R**);
   - in Risposta libera / Numerico: **scrivi** la risposta e premi **Invia** (o **Invio**).
7. Dopo ogni domanda vedi la **rivelazione**: se hai risposto correttamente, quanti punti hai ottenuto, la serie di risposte corrette e il punteggio attuale.
8. Alla fine vedi il **podio** con il tuo punteggio e una piccola animazione di coriandoli.

Il punteggio dipende dalla **correttezza** e dalla **velocità** (le risposte rapide ottengono più punti), più un **bonus serie** per le risposte corrette consecutive.

---

## 11. Gioco a turni

Perfetto per un tavolo di amici con un solo portatile/telefono.

1. Scegli un quiz e premi **Gioca**, poi la modalità **A turni**.
2. Aggiungi i nomi dei giocatori con **Aggiungi giocatore** (fino a 8). Con **✕** togli un giocatore.
3. Premi **🚀 Inizia il gioco**.
4. Per ogni domanda, l'applicazione annuncia **di chi è il turno** («Passa a...»). Il giocatore attuale preme **Sono pronto ▶** e risponde.
5. Gli altri **non** vedono se la risposta è corretta fino alla rivelazione (per non copiare).
6. Dopo che tutti hanno risposto, appare la **rivelazione** con la risposta corretta e quanto ha ottenuto ciascuno.
7. Tra le domande appare una **classifica** che mostra chi sale e chi scende.
8. Alla fine, il **podio**.

> Passa effettivamente il dispositivo da un giocatore all'altro quando l'applicazione te lo chiede.

---

## 12. Gioco dal vivo — il conduttore

Questa è la modalità «Kahoot»: tu proietti le domande, e la sala risponde dai telefoni. **Richiede l'edizione server** e la **password del conduttore**.

### Passo 1 — Accedere come conduttore

1. Apri l'indirizzo dell'applicazione.
2. Accedi come conduttore con la **password** ricevuta dall'amministratore.
   - *Al primo utilizzo del server*, se non c'è ancora una password, ti verrà chiesto di **impostarne** una (almeno 6 caratteri). Vedi anche il *Manuale dell'amministratore*.

### Passo 2 — Creare la sessione di gioco

1. Scegli il quiz e avvia un **gioco dal vivo**.
2. Facoltativamente, attiva il **🔀 mescolamento** e/o la **👥 modalità a squadre** (2–4 squadre).
3. Conferma la creazione. L'applicazione genera un **codice breve** (ad es. `ABCD`).

### Passo 3 — Invitare i partecipanti

Sulla schermata del conduttore appaiono:

- Il **codice** d'ingresso;
- un **codice QR** che i partecipanti scansionano;
- (se necessario) il link d'ingresso diretto.

I partecipanti aprono l'indirizzo, inseriscono il codice (o scansionano il QR) ed entrano nella **sala d'attesa**.

### Passo 4 — Sala d'attesa

- Vedi l'elenco dei partecipanti che si uniscono, in tempo reale.
- Se hai attivato le squadre, vedi quanti membri ha ciascuna squadra.
- Aspetti che entrino tutti, poi **avvii** il gioco.

### Passo 5 — Svolgimento del gioco

Per ogni domanda:

1. Il conduttore mostra la domanda e avvia il cronometro.
2. I partecipanti rispondono dai loro dispositivi.
3. Alla scadenza (o quando tutti rispondono), il conduttore passa alla **rivelazione**: la risposta corretta, la distribuzione delle risposte, i più rapidi.
4. Vedi la **classifica** aggiornata.
5. Passi alla domanda successiva.

Durante il gioco, i partecipanti possono inviare **reazioni emoji** che fluttuano sulla schermata del conduttore (vedi 15).

### Passo 6 — Fine

All'ultima domanda, il conduttore mostra il **podio** (top 3) e, se hai giocato a squadre, la **classifica a squadre** con la squadra vincitrice evidenziata. Da qui puoi aprire i **rapporti** (sezione 19).

---

## 13. Gioco dal vivo — il partecipante

Come partecipante **non ti serve un account o una password**.

1. Apri l'indirizzo dato dal conduttore (o scansiona il codice QR).
2. Inserisci il **codice** del gioco.
3. Scegli un **nome** (puoi premere **🎲** per un **soprannome generato** automaticamente) e un **avatar** (emoji).
4. Se il gioco è a squadre, **scegli la tua squadra** (pulsante colorato).
5. Entri nella **sala d'attesa** e aspetti che il conduttore avvii.
6. Per ogni domanda, **rispondi** sul tuo telefono prima che scada il tempo.
7. Dopo ogni domanda vedi se hai fatto punti e la tua posizione.
8. Puoi inviare **reazioni emoji** dalla tua schermata.
9. Alla fine vedi **la tua posizione** (e quella della tua squadra, se applicabile).

---

## 14. Modalità a squadre

Disponibile nel **gioco dal vivo**. Raggruppi i giocatori in squadre colorate: **🔴 Rossi, 🔵 Blu, 🟢 Verdi, 🟡 Gialli**.

**Come conduttore:**

1. Alla configurazione del gioco dal vivo, attiva la **👥 Modalità a squadre**.
2. Scegli il numero di squadre: **2, 3 o 4**.
3. Crea il gioco come al solito.

**Come funziona:**

- All'ingresso, ogni partecipante **sceglie la sua squadra**.
- Ognuno gioca e fa punti **individualmente**, ma i punteggi vengono **sommati per squadra**.
- Nella sala d'attesa, il conduttore vede il numero di membri per squadra.
- Alla fine, oltre al podio individuale, appare una **classifica a squadre** con la squadra vincitrice evidenziata.
- Ogni giocatore vede la sua squadra e la posizione della squadra.

---

## 15. Reazioni, soprannomi, mescolamento

### Reazioni emoji (gioco dal vivo)

Durante il gioco, i partecipanti hanno una barra con **👏 ❤️ 😮 😂 🔥 🎉**. Quando ne premi una, l'emoji **fluttua sulla schermata del conduttore**, creando atmosfera. Le reazioni sono limitate nel ritmo per evitare lo spam.

### Generatore di soprannomi

All'ingresso in un gioco dal vivo, il pulsante **🎲** accanto al campo del nome ti propone un **soprannome casuale** amichevole (aggettivo + sostantivo + numero), utile se non vuoi usare il tuo vero nome.

### Mescolamento (shuffle)

Alla configurazione, l'interruttore **🔀 Mescola domande e opzioni** fa sì che:

- l'ordine delle **domande** differisca a ogni gioco;
- l'ordine delle **opzioni** nelle domande a scelta multipla sia mescolato.

Vero/Falso, la risposta libera e il numerico mantengono la loro struttura. Funziona in tutte le modalità (Solo, A turni, Dal vivo).

> Il mescolamento è utile quando riutilizzi lo stesso quiz con gruppi diversi o quando vuoi scoraggiare la copiatura tra vicini.

---

## 16. Compito autonomo

La modalità «compito» (al proprio ritmo, nello stile di Quizizz) consente a ogni partecipante di risolvere il quiz **al proprio ritmo**, non in sincronia con il conduttore. **Richiede l'edizione server.**

**Come conduttore / insegnante:**

1. Scegli il quiz e crea una sessione di tipo **compito autonomo**.
2. Distribuisci il **codice** ai partecipanti.

**Come partecipante:**

1. Entri con il codice.
2. Percorri le domande una per una, al tuo ritmo.
3. Ricevi un riscontro dopo ogni domanda.
4. Alla fine vedi il tuo risultato.

Il punteggio usa le stesse regole del gioco (inclusa la vicinanza per il numerico), ma senza la pressione della velocità sincrona.

---

## 17. Attività per il pubblico

Oltre ai quiz, l'edizione server offre **attività dal vivo per il pubblico** nello stile di Slido/Mentimeter. Il conduttore crea un'attività, riceve un codice/QR, e il pubblico contribuisce dai telefoni; i risultati appaiono **in tempo reale** sulla schermata del conduttore.

Tipi disponibili:

### Nuvola di parole

Il pubblico invia parole brevi; le parole più frequenti appaiono più grandi. Buono per «descrivi in una parola...».

### Sondaggio

Il conduttore definisce le opzioni; il pubblico vota; vedi le percentuali dal vivo su barre colorate.

### Q&A (domande dal pubblico), con moderazione

Il pubblico invia domande e può **votare** (▲) quelle degli altri, così le popolari salgono. Il conduttore può:

- **moderare** — se la moderazione è attiva, le domande devono essere **approvate** prima di essere visibili a tutti;
- contrassegnare una domanda con una **stella** (★) o come **risposta** (✓);
- **nascondere** o **eliminare** domande.

### Valutazione / NPS

Il pubblico dà un voto (stelle su una scala di 5, o 0–10 per l'NPS). Vedi la media / il punteggio NPS e la distribuzione.

### Classifica

Il pubblico ordina alcune opzioni per preferenza; vedi la classifica media.

### Scale (Likert)

Più affermazioni valutate su una scala (ad es. «disaccordo ↔ accordo»); vedi la media di ogni affermazione.

### 100 punti

Ogni partecipante distribuisce un budget di 100 punti tra le opzioni; vedi come si divide il «budget» del pubblico.

> **Aperto / Chiuso:** il conduttore può **chiudere** un'attività per fermare i nuovi contributi, poi riaprirla.

---

## 18. Presentazioni (deck)

L'edizione server consente di costruire una **presentazione** con più **diapositive**, ciascuna essendo un'attività diversa (nuvola di parole, sondaggio, Q&A, valutazione, classifica, scale, 100 punti).

1. Crei una presentazione, le dai un **titolo**.
2. Aggiungi diapositive, scegliendo il tipo di ciascuna e configurandola.
3. All'esecuzione, il conduttore **naviga** tra le diapositive con **◀ / ▶**, e il pubblico vede sempre l'attività corrente.

Utile per un intero laboratorio interattivo gestito da un'unica sessione.

---

## 19. Rapporti dopo il gioco

Dopo un gioco dal vivo, il conduttore può aprire **rapporti** con:

- risultati per partecipante (punteggio, risposte corrette);
- statistiche per domanda (quanti hanno risposto, percentuale di correttezza, tempo medio);
- per scelta multipla/vero-falso, la **distribuzione** delle risposte tra le opzioni.

I rapporti possono essere **esportati** (CSV / JSON) o **stampati**.

> Per le domande a risposta libera e numeriche, il rapporto mostra le statistiche generali (quanti, correttezza), senza grafico di distribuzione tra le opzioni.

---

## 20. La ruota della fortuna

Uno strumento separato, **completamente offline**, per scelte casuali (chi risponde, chi vince, squadre a caso, ecc.). Disponibile in **entrambe le edizioni**, tramite il pulsante **🎡 Ruota** sulla schermata iniziale.

1. Premi **Ruota**.
2. Nel pannello a destra, scrivi gli **elementi**, uno per riga (nomi, opzioni). Puoi averne fino a 24.
3. Premi **🎯 Gira**. La ruota gira e si ferma su un vincitore.
4. Opzioni:
   - **Rimuovi il vincitore dopo ogni estrazione** — utile per estrarre a rotazione, senza ripetizione (si ferma a un minimo di 2 elementi);
   - **🔀 Mescola** — riordina gli elementi;
   - **↺ Reimposta** — torna all'elenco predefinito.

L'elenco viene ricordato localmente tra le sessioni.

---

## 21. Installare come app (PWA)

L'edizione server può essere **installata** come app su telefono o desktop (funziona anche offline per le schermate già visitate).

- **Su telefono (Chrome/Android):** apri l'indirizzo, poi il menu del browser → *Aggiungi alla schermata Home*.
- **Su desktop (Chrome/Edge):** appare un'icona di installazione nella barra degli indirizzi.
- **Su iPhone (Safari):** il pulsante *Condividi* → *Aggiungi a Home*.

> Il PWA richiede che il server sia servito tramite **HTTPS** (vedi il *Manuale dell'amministratore*).

---

## 22. Consigli e buone pratiche

- **Prova prima.** Prima di un laboratorio con pubblico, avvia un gioco di prova con 2–3 dispositivi, per assicurarti che la rete, il codice d'ingresso e ogni tipo di domanda funzionino nelle tue condizioni reali.
- **Esporta i tuoi quiz.** Soprattutto nell'edizione offline, fai un backup tramite esportazione JSON — non affidarti solo alla memoria del browser.
- **Tempo adeguato.** Metti 20 secondi per le domande normali, 5–10 per quelle brevi, 60–120 per quelle che richiedono riflessione.
- **Risposta libera con generosità.** Aggiungi tutte le varianti accettate plausibili (sinonimi, forme con/senza diacritici, abbreviazioni).
- **Numerico con tolleranza realistica.** Scegli una tolleranza che premi la vicinanza ma non renda corretta ogni risposta.
- **Il mescolamento** è tuo amico quando riutilizzi lo stesso quiz con gruppi diversi.
- **Schermo grande per il conduttore.** Nel gioco dal vivo, proietta la schermata del conduttore; i partecipanti vedono solo i pulsanti sul telefono.

---

## 23. Risoluzione dei problemi

**Non vedo i quiz che ho creato.**
Nell'edizione offline, i quiz stanno nella memoria di quel browser. Verifica di usare lo stesso browser/dispositivo e di non aver svuotato i dati. In futuro, esportali come backup.

**Ho importato un file e non appare nulla / appaiono meno domande.**
Il file può essere non valido o superare le 100 domande (l'eccedenza viene ignorata). Le domande senza testo o senza risposte valide vengono saltate automaticamente.

**Nel gioco dal vivo, i partecipanti non riescono a connettersi.**
Verifica: sono sulla stessa rete / hanno accesso all'indirizzo; hanno inserito correttamente il codice; il gioco non è già stato chiuso. Chiedi all'amministratore di confermare che il server sia avviato e accessibile.

**Il pulsante di installazione (PWA) non appare.**
Il PWA richiede HTTPS. Se il server è su HTTP semplice, l'installazione non è disponibile (ma l'applicazione funziona normalmente nel browser).

**Il suono non funziona.**
Verifica l'interruttore del suono nella barra superiore e il volume del dispositivo. Alcuni browser richiedono una prima interazione (un clic) prima di riprodurre il suono.

**Il campo di risposta libera non tiene il mio testo.**
Scrivi e premi **Invia** o **Invio** prima che scada il tempo. Se il tempo finisce, la domanda è considerata mancata.

---

## 24. Riservatezza

- L'applicazione **non ha telemetria** e **non traccia** gli utenti.
- Nell'edizione offline, **tutti i dati restano sul tuo dispositivo** (la memoria del browser e i file esportati).
- Nell'edizione server, le sessioni di gioco e gli eventuali quiz vengono conservati sul server dell'amministratore (vedi il *Manuale dell'amministratore* per i dettagli su archiviazione ed eliminazione).
- I partecipanti a un gioco dal vivo **non hanno bisogno di un account**; usano solo un nome a loro scelta (che può anche essere un soprannome generato).

---

*Undava — uno strumento a file unico, offline-first, trade-free.
Per l'installazione, la configurazione e la manutenzione, vedi il **Manuale dell'amministratore**.*
</script>
<script type="text/markdown" id="manual-user-es">
# Undava — Manual de usuario

*Una guía completa, paso a paso, para crear cuestionarios, jugar en clase y realizar actividades de audiencia.*

---

## Índice

1. [Qué es Undava](#1-que-es-undava)
2. [Las dos ediciones y cuándo usar cada una](#2-las-dos-ediciones)
3. [Primeros pasos](#3-primeros-pasos)
4. [Recorrido por la interfaz](#4-recorrido-interfaz)
5. [La biblioteca de cuestionarios](#5-biblioteca)
6. [Crear un cuestionario en el editor](#6-crear-un-cuestionario)
7. [Los cuatro tipos de preguntas](#7-tipos-de-preguntas)
8. [Importar y exportar (JSON)](#8-importar-exportar)
9. [Los modos de juego](#9-modos-de-juego)
10. [Juego Solo — paso a paso](#10-juego-solo)
11. [Juego por turnos — paso a paso](#11-juego-por-turnos)
12. [Juego en directo sincronizado — el anfitrión](#12-directo-anfitrion)
13. [Juego en directo sincronizado — el participante](#13-directo-participante)
14. [Modo por equipos](#14-modo-equipos)
15. [Reacciones, apodos, mezcla](#15-reacciones-apodos-mezcla)
16. [Tarea autónoma](#16-autonoma)
17. [Actividades de audiencia (Slido/Mentimeter)](#17-actividades-audiencia)
18. [Presentaciones (deck)](#18-presentaciones)
19. [Informes después del juego](#19-informes)
20. [La ruleta de la fortuna](#20-ruleta)
21. [Instalar como aplicación (PWA)](#21-instalar-pwa)
22. [Consejos y buenas prácticas](#22-consejos)
23. [Resolución de problemas para usuarios](#23-resolucion-problemas)
24. [Privacidad](#24-privacidad)

---

## 1. Qué es Undava

Undava es un juego de cuestionarios en el espíritu de Kahoot, combinado con actividades de audiencia al estilo de Slido/Mentimeter. Puedes crear cuestionarios, jugarlos solo o con un grupo (en un solo dispositivo o en varios, de forma sincronizada), y ejecutar actividades en directo (nube de palabras, encuestas, preguntas del público).

La aplicación es **offline-first**, **sin telemetría**, **sin cuentas obligatorias para los participantes** y **trade-free**. Tus cuestionarios te pertenecen y se guardan localmente o como archivos JSON que puedes mover a cualquier parte.

---

## 2. Las dos ediciones

La aplicación viene en dos formas. Ambas se llaman «Undava» y usan el **mismo formato JSON** para los cuestionarios, así que puedes moverlos libremente entre las dos.

### `quiz-fara-frontiere.html` — la edición totalmente sin conexión

- Un único archivo HTML. Ábrelo con doble clic, **sin instalación, sin internet, sin servidor**.
- Ideal para: preparar en casa, juegos en solitario, juegos «por turnos» (varios jugadores por turnos, en el mismo dispositivo), en clase sin red.
- Contiene: los **4 tipos de preguntas**, los juegos **Solo** y **Por turnos**, la **mezcla** de preguntas y opciones, y la **Ruleta de la fortuna**.
- Los cuestionarios se guardan en el almacenamiento local del navegador (y mediante exportación JSON).

### `index.php` — la edición servidor (completa)

- Debe alojarse en un servidor con PHP (ver el *Manual del administrador*). **No** se abre directamente con doble clic.
- Contiene todo lo que tiene la edición sin conexión **más** las funciones que necesitan red:
  - **Juego en directo sincronizado** (varios jugadores, en sus dispositivos, en tiempo real);
  - **Tarea autónoma** (cada uno resuelve a su ritmo);
  - **Actividades de audiencia** (nube de palabras, encuesta, preguntas y respuestas moderadas, valoración/NPS, clasificación, escalas, 100 puntos);
  - **Presentaciones** con varias actividades en diapositivas;
  - **Modo por equipos**, **reacciones emoji**, **códigos + QR de entrada**, **informes** tras el juego, **instalación como aplicación (PWA)**.

> **En resumen:** si solo quieres crear y practicar solo o con amigos en un portátil, basta el archivo HTML. Si quieres un juego en directo con la sala llena, necesitas la edición servidor.

---

## 3. Primeros pasos

### A. La edición sin conexión (HTML)

1. Consigue el archivo `quiz-fara-frontiere.html`.
2. Haz doble clic en él. Se abre en tu navegador predeterminado.
3. Listo — estás en la pantalla de inicio. No hace falta nada más.

### B. La edición servidor (index.php)

1. El administrador te da una **dirección web** (p. ej. `https://ejemplo.com/quiz/`).
2. Abre la dirección en el navegador.
3. Para **crear y alojar** juegos en directo, necesitarás la **contraseña de anfitrión** del administrador (ver la sección 12). Como **participante**, no necesitas contraseña.

### El idioma de la interfaz

La aplicación habla 7 idiomas (rumano, inglés, francés, italiano, español, portugués, alemán). Cambia el idioma en la esquina superior derecha. Tu elección se recuerda.

---

## 4. Recorrido por la interfaz

La pantalla de inicio tiene, de arriba abajo:

- **La barra superior** — el logotipo «Undava» (hacer clic en él te devuelve al Inicio), el selector de idioma y el interruptor de sonido.
- **El título** y un breve subtítulo.
- **Los grandes botones de acción:**
  - **Jugar** — abre la biblioteca de cuestionarios, donde eliges a qué jugar.
  - **Crear** — abre el editor para un cuestionario nuevo.
  - **Importar** — cargas un cuestionario desde un archivo JSON.
  - **Ruleta** — abre la Ruleta de la fortuna (selector aleatorio).
- **Características** mostradas como insignias: sin conexión, gratis, privado, abierto.

> En el teléfono, los botones se disponen en vertical y todo el contenido se adapta a la pantalla pequeña.

---

## 5. La biblioteca de cuestionarios

Pulsas **Jugar** y llegas a la biblioteca. Aquí ves:

- **Cuestionarios de demostración** (marcados «DEMO») — ejemplos ya hechos con los que puedes probar la aplicación de inmediato.
- **Tus cuestionarios** (marcados «TUYO») — los creados o importados por ti.

Cada cuestionario aparece como una tarjeta de color que muestra el título, la descripción y el **número de preguntas**. En cada tarjeta tienes opciones para:

- **Jugar** — inicia la configuración del juego (eliges el modo).
- **Editar** — abre el cuestionario en el editor.
- **Exportar** — guarda el cuestionario como archivo JSON.
- **Eliminar** — quita el cuestionario (solo para tus cuestionarios; las demos no se pueden eliminar).

> Un cuestionario de demostración no se edita directamente: si pulsas Editar en una demo, la aplicación te hace una **copia** editable (marcada «✎»), para que no estropees el original.

---

## 6. Crear un cuestionario

Pulsas **Crear** (o **Editar** en un cuestionario existente). El editor tiene dos zonas: el encabezado del cuestionario y la lista de preguntas.

### Paso 1 — Título y descripción

1. En el campo **Título**, escribe el nombre del cuestionario (obligatorio, hasta 80–200 caracteres).
2. En el campo **Descripción**, escribe opcionalmente un breve resumen (qué cubre el cuestionario).

### Paso 2 — Añadir preguntas

- Un cuestionario nuevo empieza con una pregunta vacía.
- Pulsa **＋ Añadir pregunta** para añadir más.
- Puedes tener **hasta 100 preguntas** en un cuestionario.

### Paso 3 — Configurar cada pregunta

Cada pregunta tiene, en su encabezado:

- Un **selector de tipo** (Opción múltiple / Verdadero-Falso / Respuesta libre / Numérica) — ver la sección 7.
- Un **selector de tiempo** — cuánto tiempo tienen los jugadores para responder: **5, 10, 20, 30, 60 o 120 segundos**.
- Un **selector de puntos** — **Estándar (1000)** o **Doble (2000)** para las preguntas más difíciles.
- Botones **↑ / ↓** para reordenar la pregunta en la lista.
- El botón **🗑** para eliminar la pregunta.

En el cuerpo de la pregunta escribes el **texto de la pregunta** (hasta 200–500 caracteres) y configuras las respuestas según el tipo.

### Paso 4 — Guardar

Pulsas **✓ Guardar**. La aplicación comprueba primero:

- que el cuestionario tenga título;
- que cada pregunta tenga texto;
- que cada pregunta tenga sus respuestas rellenadas correctamente para su tipo (ver más abajo).

Si falta algo, recibes un mensaje claro que te dice qué pregunta corregir. Tras guardar, el cuestionario aparece en la biblioteca en «TUYO».

> **Nota sin conexión:** en la edición HTML, los cuestionarios se guardan en el almacenamiento local del navegador. Si vacías los datos del navegador o usas otro navegador/dispositivo, ya no los ves — por eso es importante **exportarlos** (sección 8) como copia de seguridad.

---

## 7. Los cuatro tipos de preguntas

Eliges el tipo desde el selector del encabezado de la pregunta.

### 7.1. Opción múltiple (4 opciones)

La pregunta clásica de opciones múltiples.

1. Escribe el texto de la pregunta.
2. Rellena las opciones de respuesta (**de 2 a 4**).
3. Marca **Correcta** junto a la opción correcta (exactamente una).
4. Con **✕** eliminas una opción; con **Añadir respuesta** añades una (hasta 4).

Durante el juego, los jugadores pulsan una de las pastillas de colores con formas (▲ ♦ ● ■).

### 7.2. Verdadero / Falso

Una pregunta con solo dos opciones fijas: **Verdadero** y **Falso**.

1. Escribe la afirmación.
2. Marca qué opción (Verdadero o Falso) es la correcta.

Las etiquetas «Verdadero»/«Falso» son fijas y no se editan.

### 7.3. Respuesta libre

El jugador **escribe** la respuesta en lugar de elegirla de una lista.

1. Escribe la pregunta.
2. En **La respuesta correcta (mostrada)**, escribe la respuesta correcta (la primera variante es la que se muestra a todos en la revelación como «la respuesta correcta»).
3. Opcionalmente, pulsa **Añadir variante aceptada** para añadir sinónimos o grafías alternativas (p. ej. para «Bucarest» puedes aceptar también «Bucuresti»). Puedes tener hasta 6 variantes aceptadas.

**Cómo se puntúa:** la aplicación también acepta **pequeños errores tipográficos** (coincidencia aproximada) e **ignora los signos diacríticos, las mayúsculas y la puntuación**. Por ejemplo, «pariss» se aceptará por «Paris». Las palabras muy cortas (≤3 letras) se exigen exactas, para que no haya coincidencias accidentales.

> Consejo: añade a las variantes aceptadas todas las formas plausibles que alguien podría escribir (abreviaturas, formas con/sin diacríticos, sinónimos).

### 7.4. Numérica («adivina el número»)

El jugador escribe un **número**, y la puntuación aumenta cuanto más cerca esté de la respuesta.

1. Escribe la pregunta.
2. En **El número correcto**, pon el valor exacto (p. ej. `42`).
3. En **Tolerancia aceptada (±)**, pon cuánto puede alejarse una respuesta y contar aun así como correcta (p. ej. `10` significa que cualquier valor entre 32 y 52 es correcto).

**Cómo se puntúa:**

- La respuesta **exacta** obtiene la puntuación máxima.
- Una respuesta **dentro del intervalo** obtiene puntos proporcionales a la cercanía: en el borde del intervalo obtiene la mitad, y cuanto más cerca del exacto, más.
- Fuera del intervalo = 0 puntos.
- **Tolerancia 0** significa que solo se acepta la respuesta exacta.
- Se aceptan los **decimales con coma** (p. ej. `3,5`) y los números negativos.
- En el juego en directo, la cercanía se combina con la **velocidad** de la respuesta.

---

## 8. Importar y exportar

El formato es JSON, idéntico en ambas ediciones.

### Exportar

1. En la biblioteca, pulsa **Exportar** en el cuestionario deseado.
2. Se descarga un archivo `.json` con el nombre del cuestionario.
3. Guárdalo como copia de seguridad o envíaselo a alguien.

### Importar

1. En la pantalla de inicio, pulsa **Importar**.
2. **Pega** el contenido JSON en el cuadro de texto (o, según el caso, carga el archivo).
3. Confirma. El cuestionario aparece en tu biblioteca.

La aplicación comprueba el archivo al importar: las preguntas no válidas se ignoran, y un cuestionario no puede superar las **100 preguntas** (el excedente se ignora). El texto potencialmente peligroso se neutraliza automáticamente, así que puedes importar con seguridad archivos recibidos de otros.

> Como ambas ediciones usan el mismo formato, un cuestionario exportado desde HTML se importa en `index.php` y viceversa.

---

## 9. Los modos de juego

Después de pulsar **Jugar** en un cuestionario, eliges el modo:

| Modo | Dónde | Descripción |
|-----|------|-----------|
| **Solo** | ambas ediciones | Juegas solo, contra el reloj. |
| **Por turnos** | ambas ediciones | Varios jugadores en el **mismo** dispositivo, por turnos. |
| **En directo** | solo servidor | Varios jugadores en **sus dispositivos**, sincronizados (Kahoot). |
| **Tarea autónoma** | solo servidor | Cada uno resuelve a su ritmo, con un código. |

En la configuración también tienes:

- **🔀 Mezclar preguntas y opciones** — el orden difiere en cada juego (ver 15).
- **👥 Modo por equipos** (solo En directo) — agrupas a los jugadores en 2–4 equipos (ver 14).
- **La lista de jugadores** (en Solo/Por turnos) — los nombres de los participantes.

---

## 10. Juego Solo

1. Elige un cuestionario y pulsa **Jugar**.
2. Elige el modo **Solo**.
3. Opcionalmente, escribe tu nombre (de lo contrario apareces como «Tú»).
4. Pulsa **🚀 Empezar el juego**.
5. Ves una **cuenta atrás**, luego la primera pregunta.
6. Responde antes de que se acabe el tiempo:
   - en Opción múltiple / Verdadero-Falso: pulsa la opción (o las teclas **1–4** / **Q W E R**);
   - en Respuesta libre / Numérica: **escribe** la respuesta y pulsa **Enviar** (o **Intro**).
7. Después de cada pregunta ves la **revelación**: si has respondido correctamente, cuántos puntos has obtenido, la racha de respuestas correctas y la puntuación actual.
8. Al final ves el **podio** con tu puntuación y una pequeña animación de confeti.

La puntuación depende de la **corrección** y de la **velocidad** (las respuestas rápidas obtienen más puntos), más un **bono de racha** por respuestas correctas consecutivas.

---

## 11. Juego por turnos

Perfecto para una mesa de amigos con un solo portátil/teléfono.

1. Elige un cuestionario y pulsa **Jugar**, luego el modo **Por turnos**.
2. Añade los nombres de los jugadores con **Añadir jugador** (hasta 8). Con **✕** quitas a un jugador.
3. Pulsa **🚀 Empezar el juego**.
4. Para cada pregunta, la aplicación anuncia **a quién le toca** («Pasa a...»). El jugador actual pulsa **Estoy listo ▶** y responde.
5. Los demás **no** ven si la respuesta es correcta hasta la revelación (para que no copien).
6. Después de que todos hayan respondido, aparece la **revelación** con la respuesta correcta y cuánto ha obtenido cada uno.
7. Entre preguntas aparece una **clasificación** que muestra quién sube y quién baja.
8. Al final, el **podio**.

> Pasa efectivamente el dispositivo de un jugador a otro cuando la aplicación te lo pida.

---

## 12. Juego en directo — el anfitrión

Este es el modo «Kahoot»: tú proyectas las preguntas, y la sala responde desde los teléfonos. **Requiere la edición servidor** y la **contraseña de anfitrión**.

### Paso 1 — Iniciar sesión como anfitrión

1. Abre la dirección de la aplicación.
2. Inicia sesión como anfitrión con la **contraseña** recibida del administrador.
   - *En el primer uso del servidor*, si aún no hay contraseña, se te pedirá **establecer** una (mínimo 6 caracteres). Ver también el *Manual del administrador*.

### Paso 2 — Crear la sesión de juego

1. Elige el cuestionario e inicia un **juego en directo**.
2. Opcionalmente, activa la **🔀 mezcla** o el **👥 modo por equipos** (2–4 equipos).
3. Confirma la creación. La aplicación genera un **código corto** (p. ej. `ABCD`).

### Paso 3 — Invitar a los participantes

En la pantalla del anfitrión aparecen:

- El **código** de entrada;
- un **código QR** que los participantes escanean;
- (según el caso) el enlace de entrada directo.

Los participantes abren la dirección, introducen el código (o escanean el QR) y entran en la **sala de espera**.

### Paso 4 — Sala de espera

- Ves la lista de participantes que se unen, en tiempo real.
- Si has activado los equipos, ves cuántos miembros tiene cada equipo.
- Esperas a que entren todos, luego **inicias** el juego.

### Paso 5 — Desarrollo del juego

Para cada pregunta:

1. El anfitrión muestra la pregunta e inicia el cronómetro.
2. Los participantes responden desde sus dispositivos.
3. Al expirar (o cuando todos responden), el anfitrión pasa a la **revelación**: la respuesta correcta, la distribución de las respuestas, los más rápidos.
4. Ves la **clasificación** actualizada.
5. Pasas a la siguiente pregunta.

Durante el juego, los participantes pueden enviar **reacciones emoji** que flotan por la pantalla del anfitrión (ver 15).

### Paso 6 — Final

En la última pregunta, el anfitrión muestra el **podio** (top 3) y, si has jugado por equipos, la **clasificación por equipos** con el equipo ganador resaltado. Desde aquí puedes abrir los **informes** (sección 19).

---

## 13. Juego en directo — el participante

Como participante **no necesitas cuenta ni contraseña**.

1. Abre la dirección dada por el anfitrión (o escanea el código QR).
2. Introduce el **código** del juego.
3. Elige un **nombre** (puedes pulsar **🎲** para un **apodo generado** automáticamente) y un **avatar** (emoji).
4. Si el juego es por equipos, **elige tu equipo** (botón de color).
5. Entras en la **sala de espera** y esperas a que el anfitrión inicie.
6. Para cada pregunta, **responde** en tu teléfono antes de que se acabe el tiempo.
7. Después de cada pregunta ves si has puntuado y tu posición.
8. Puedes enviar **reacciones emoji** desde tu pantalla.
9. Al final ves **tu puesto** (y el de tu equipo, si procede).

---

## 14. Modo por equipos

Disponible en el **juego en directo**. Agrupas a los jugadores en equipos de colores: **🔴 Rojos, 🔵 Azules, 🟢 Verdes, 🟡 Amarillos**.

**Como anfitrión:**

1. En la configuración del juego en directo, activa el **👥 Modo por equipos**.
2. Elige el número de equipos: **2, 3 o 4**.
3. Crea el juego como de costumbre.

**Cómo funciona:**

- Al entrar, cada participante **elige su equipo**.
- Cada uno juega y puntúa **individualmente**, pero las puntuaciones se **suman por equipo**.
- En la sala de espera, el anfitrión ve el número de miembros por equipo.
- Al final, además del podio individual, aparece una **clasificación por equipos** con el equipo ganador resaltado.
- Cada jugador ve su equipo y la posición del equipo.

---

## 15. Reacciones, apodos, mezcla

### Reacciones emoji (juego en directo)

Durante el juego, los participantes tienen una barra con **👏 ❤️ 😮 😂 🔥 🎉**. Cuando pulsas una, el emoji **flota por la pantalla del anfitrión**, creando ambiente. Las reacciones tienen un límite de ritmo para que no haya spam.

### Generador de apodos

Al entrar en un juego en directo, el botón **🎲** junto al campo del nombre te propone un **apodo aleatorio** amistoso (adjetivo + sustantivo + número), útil si no quieres usar tu nombre real.

### Mezcla (shuffle)

En la configuración, el interruptor **🔀 Mezclar preguntas y opciones** hace que:

- el orden de las **preguntas** difiera en cada juego;
- el orden de las **opciones** en las preguntas de opción múltiple se mezcle.

Verdadero/Falso, la respuesta libre y la numérica mantienen su estructura. Funciona en todos los modos (Solo, Por turnos, En directo).

> La mezcla es tu amiga cuando reutilizas el mismo cuestionario con grupos diferentes o cuando quieres desalentar la copia entre vecinos.

---

## 16. Tarea autónoma

El modo «tarea» (a tu ritmo, al estilo de Quizizz) permite a cada participante resolver el cuestionario **a su propio ritmo**, no sincronizado con el anfitrión. **Requiere la edición servidor.**

**Como anfitrión / profesor:**

1. Elige el cuestionario y crea una sesión de tipo **tarea autónoma**.
2. Distribuye el **código** a los participantes.

**Como participante:**

1. Entras con el código.
2. Recorres las preguntas una a una, a tu ritmo.
3. Recibes retroalimentación después de cada pregunta.
4. Al final ves tu resultado.

La puntuación usa las mismas reglas que el juego (incluida la cercanía para la numérica), pero sin la presión de la velocidad sincronizada.

---

## 17. Actividades de audiencia

Además de los cuestionarios, la edición servidor ofrece **actividades en directo de audiencia** al estilo de Slido/Mentimeter. El anfitrión crea una actividad, recibe un código/QR, y el público contribuye desde los teléfonos; los resultados aparecen **en tiempo real** en la pantalla del anfitrión.

Tipos disponibles:

### Nube de palabras

El público envía palabras cortas; las palabras más frecuentes aparecen más grandes. Bueno para «describe en una palabra...».

### Encuesta

El anfitrión define opciones; el público vota; ves los porcentajes en directo en barras de colores.

### Preguntas y respuestas (del público), con moderación

El público envía preguntas y puede **votar** (▲) las de los demás, para que las populares suban. El anfitrión puede:

- **moderar** — si la moderación está activa, las preguntas deben ser **aprobadas** antes de ser visibles para todos;
- marcar una pregunta con una **estrella** (★) o como **respondida** (✓);
- **ocultar** o **eliminar** preguntas.

### Valoración / NPS

El público da una nota (estrellas en una escala de 5, o 0–10 para el NPS). Ves la media / la puntuación NPS y la distribución.

### Clasificación

El público ordena unas opciones por preferencia; ves la clasificación media.

### Escalas (Likert)

Varias afirmaciones evaluadas en una escala (p. ej. «desacuerdo ↔ acuerdo»); ves la media de cada afirmación.

### 100 puntos

Cada participante distribuye un presupuesto de 100 puntos entre las opciones; ves cómo se reparte el «presupuesto» de la audiencia.

> **Abierto / Cerrado:** el anfitrión puede **cerrar** una actividad para detener las nuevas contribuciones, y luego reabrirla.

---

## 18. Presentaciones (deck)

La edición servidor permite construir una **presentación** con varias **diapositivas**, siendo cada una una actividad diferente (nube de palabras, encuesta, preguntas y respuestas, valoración, clasificación, escalas, 100 puntos).

1. Creas una presentación, le das un **título**.
2. Añades diapositivas, eligiendo el tipo de cada una y configurándola.
3. En la ejecución, el anfitrión **navega** entre las diapositivas con **◀ / ▶**, y el público siempre ve la actividad actual.

Útil para todo un taller interactivo ejecutado desde una sola sesión.

---

## 19. Informes después del juego

Después de un juego en directo, el anfitrión puede abrir **informes** con:

- resultados por participante (puntuación, respuestas correctas);
- estadísticas por pregunta (cuántos respondieron, porcentaje de acierto, tiempo medio);
- para opción múltiple/verdadero-falso, la **distribución** de las respuestas entre las opciones.

Los informes se pueden **exportar** (CSV / JSON) o **imprimir**.

> Para las preguntas de respuesta libre y numéricas, el informe muestra las estadísticas generales (cuántos, acierto), sin gráfico de distribución entre las opciones.

---

## 20. La ruleta de la fortuna

Una herramienta separada, **totalmente sin conexión**, para elecciones aleatorias (quién responde, quién gana, equipos al azar, etc.). Disponible en **ambas ediciones**, mediante el botón **🎡 Ruleta** de la pantalla de inicio.

1. Pulsa **Ruleta**.
2. En el panel de la derecha, escribe los **elementos**, uno por línea (nombres, opciones). Puedes tener hasta 24.
3. Pulsa **🎯 Girar**. La ruleta gira y se detiene en un ganador.
4. Opciones:
   - **Quitar al ganador después de cada tirada** — útil para sortear por turnos, sin repetición (se detiene en un mínimo de 2 elementos);
   - **🔀 Mezclar** — reordena los elementos;
   - **↺ Restablecer** — vuelve a la lista predeterminada.

La lista se recuerda localmente entre sesiones.

---

## 21. Instalar como aplicación (PWA)

La edición servidor se puede **instalar** como aplicación en el teléfono o el escritorio (también funciona sin conexión para las pantallas ya visitadas).

- **En el teléfono (Chrome/Android):** abre la dirección, luego el menú del navegador → *Añadir a la pantalla de inicio*.
- **En el escritorio (Chrome/Edge):** aparece un icono de instalación en la barra de direcciones.
- **En iPhone (Safari):** el botón *Compartir* → *Añadir a pantalla de inicio*.

> El PWA requiere que el servidor se sirva por **HTTPS** (ver el *Manual del administrador*).

---

## 22. Consejos y buenas prácticas

- **Prueba antes.** Antes de un taller con público, ejecuta un juego de prueba con 2–3 dispositivos, para asegurarte de que la red, el código de entrada y cada tipo de pregunta funcionan en tus condiciones reales.
- **Exporta tus cuestionarios.** Sobre todo en la edición sin conexión, haz una copia de seguridad mediante exportación JSON — no confíes solo en la memoria del navegador.
- **Tiempo adecuado.** Pon 20 segundos para las preguntas normales, 5–10 para las cortas, 60–120 para las que requieren reflexión.
- **Respuesta libre con generosidad.** Añade todas las variantes aceptadas plausibles (sinónimos, formas con/sin diacríticos, abreviaturas).
- **Numérica con tolerancia realista.** Elige una tolerancia que recompense la cercanía pero no haga correcta cualquier respuesta.
- **La mezcla** es tu amiga cuando reutilizas el mismo cuestionario con grupos diferentes.
- **Pantalla grande para el anfitrión.** En el juego en directo, proyecta la pantalla del anfitrión; los participantes solo ven los botones en su teléfono.

---

## 23. Resolución de problemas

**No veo los cuestionarios que he creado.**
En la edición sin conexión, los cuestionarios están en la memoria de ese navegador. Comprueba que usas el mismo navegador/dispositivo y que no has vaciado los datos. En el futuro, expórtalos como copia de seguridad.

**He importado un archivo y no aparece nada / aparecen menos preguntas.**
El archivo puede ser no válido o superar las 100 preguntas (el excedente se ignora). Las preguntas sin texto o sin respuestas válidas se saltan automáticamente.

**En el juego en directo, los participantes no pueden conectarse.**
Comprueba: están en la misma red / tienen acceso a la dirección; han introducido el código correctamente; el juego no se ha cerrado ya. Pide al administrador que confirme que el servidor está iniciado y accesible.

**El botón de instalación (PWA) no aparece.**
El PWA requiere HTTPS. Si el servidor está en HTTP simple, la instalación no está disponible (pero la aplicación funciona normalmente en el navegador).

**El sonido no funciona.**
Comprueba el interruptor de sonido en la barra superior y el volumen del dispositivo. Algunos navegadores requieren una primera interacción (un clic) antes de reproducir sonido.

**El campo de respuesta libre no guarda mi texto.**
Escribe y pulsa **Enviar** o **Intro** antes de que expire el tiempo. Si el tiempo se acaba, la pregunta se considera fallada.

---

## 24. Privacidad

- La aplicación **no tiene telemetría** y **no rastrea** a los usuarios.
- En la edición sin conexión, **todos los datos permanecen en tu dispositivo** (la memoria del navegador y los archivos exportados).
- En la edición servidor, las sesiones de juego y los posibles cuestionarios se conservan en el servidor del administrador (ver el *Manual del administrador* para más detalles sobre almacenamiento y eliminación).
- Los participantes en un juego en directo **no necesitan cuenta**; usan solo un nombre de su elección (que también puede ser un apodo generado).

---

*Undava — una herramienta de archivo único, offline-first, trade-free.
Para la instalación, la configuración y el mantenimiento, ver el **Manual del administrador**.*
</script>
<script type="text/markdown" id="manual-user-pt">
# Undava — Manual do utilizador

*Um guia completo, passo a passo, para criar questionários, jogar na aula e realizar atividades de audiência.*

---

## Índice

1. [O que é o Undava](#1-o-que-e-o-undava)
2. [As duas edições e quando usar cada uma](#2-as-duas-edicoes)
3. [Primeiros passos](#3-primeiros-passos)
4. [Visita à interface](#4-visita-interface)
5. [A biblioteca de questionários](#5-biblioteca)
6. [Criar um questionário no editor](#6-criar-um-questionario)
7. [Os quatro tipos de perguntas](#7-tipos-de-perguntas)
8. [Importar e exportar (JSON)](#8-importar-exportar)
9. [Os modos de jogo](#9-modos-de-jogo)
10. [Jogo Solo — passo a passo](#10-jogo-solo)
11. [Jogo à vez — passo a passo](#11-jogo-a-vez)
12. [Jogo ao vivo síncrono — o anfitrião](#12-ao-vivo-anfitriao)
13. [Jogo ao vivo síncrono — o participante](#13-ao-vivo-participante)
14. [Modo por equipas](#14-modo-equipas)
15. [Reações, alcunhas, baralhar](#15-reacoes-alcunhas-baralhar)
16. [Trabalho autónomo](#16-autonomo)
17. [Atividades de audiência (Slido/Mentimeter)](#17-atividades-audiencia)
18. [Apresentações (deck)](#18-apresentacoes)
19. [Relatórios após o jogo](#19-relatorios)
20. [A roda da fortuna](#20-roda)
21. [Instalar como aplicação (PWA)](#21-instalar-pwa)
22. [Conselhos e boas práticas](#22-conselhos)
23. [Resolução de problemas para utilizadores](#23-resolucao-problemas)
24. [Privacidade](#24-privacidade)

---

## 1. O que é o Undava

O Undava é um jogo de questionários no espírito do Kahoot, combinado com atividades de audiência ao estilo do Slido/Mentimeter. Podes criar questionários, jogá-los sozinho ou com um grupo (num único dispositivo ou em vários, de forma síncrona), e executar atividades ao vivo (nuvem de palavras, sondagens, perguntas do público).

A aplicação é **offline-first**, **sem telemetria**, **sem contas obrigatórias para os participantes** e **trade-free**. Os teus questionários pertencem-te e são guardados localmente ou como ficheiros JSON que podes mover para qualquer lado.

---

## 2. As duas edições

A aplicação vem em duas formas. Ambas se chamam «Undava» e usam o **mesmo formato JSON** para os questionários, por isso podes movê-los livremente entre as duas.

### `quiz-fara-frontiere.html` — a edição totalmente offline

- Um único ficheiro HTML. Abre-o com um duplo clique, **sem instalação, sem internet, sem servidor**.
- Ideal para: preparar em casa, jogos a solo, jogos «à vez» (vários jogadores por turnos, no mesmo dispositivo), na aula sem rede.
- Contém: os **4 tipos de perguntas**, os jogos **Solo** e **À vez**, o **baralhar** das perguntas e das opções, e a **Roda da fortuna**.
- Os questionários são guardados no armazenamento local do navegador (e através da exportação JSON).

### `index.php` — a edição servidor (completa)

- Tem de ser alojada num servidor com PHP (ver o *Manual do administrador*). **Não** se abre diretamente com um duplo clique.
- Contém tudo o que a edição offline tem **mais** as funções que precisam de rede:
  - **Jogo ao vivo síncrono** (vários jogadores, nos seus dispositivos, em tempo real);
  - **Trabalho autónomo** (cada um resolve ao seu ritmo);
  - **Atividades de audiência** (nuvem de palavras, sondagem, perguntas e respostas moderadas, avaliação/NPS, classificação, escalas, 100 pontos);
  - **Apresentações** com várias atividades em diapositivos;
  - **Modo por equipas**, **reações emoji**, **códigos + QR de entrada**, **relatórios** após o jogo, **instalação como aplicação (PWA)**.

> **Em resumo:** se só queres criar e praticar sozinho ou com amigos num portátil, o ficheiro HTML chega. Se queres um jogo ao vivo com a sala cheia, precisas da edição servidor.

---

## 3. Primeiros passos

### A. A edição offline (HTML)

1. Obtém o ficheiro `quiz-fara-frontiere.html`.
2. Faz duplo clique nele. Abre-se no teu navegador predefinido.
3. Pronto — estás no ecrã inicial. Não é preciso mais nada.

### B. A edição servidor (index.php)

1. O administrador dá-te um **endereço web** (por ex. `https://exemplo.com/quiz/`).
2. Abre o endereço no navegador.
3. Para **criar e alojar** jogos ao vivo, vais precisar da **palavra-passe de anfitrião** do administrador (ver a secção 12). Como **participante**, não precisas de palavra-passe.

### O idioma da interface

A aplicação fala 7 idiomas (romeno, inglês, francês, italiano, espanhol, português, alemão). Muda o idioma no canto superior direito. A tua escolha fica memorizada.

---

## 4. Visita à interface

O ecrã inicial tem, de cima para baixo:

- **A barra superior** — o logótipo «Undava» (clicar nele leva-te de volta ao Início), o seletor de idioma e o interruptor de som.
- **O título** e um breve subtítulo.
- **Os grandes botões de ação:**
  - **Jogar** — abre a biblioteca de questionários, onde escolhes o que jogar.
  - **Criar** — abre o editor para um questionário novo.
  - **Importar** — carregas um questionário a partir de um ficheiro JSON.
  - **Roda** — abre a Roda da fortuna (seletor aleatório).
- **Características** mostradas como emblemas: offline, gratuito, privado, aberto.

> No telemóvel, os botões dispõem-se na vertical e todo o conteúdo se adapta ao ecrã pequeno.

---

## 5. A biblioteca de questionários

Carregas em **Jogar** e chegas à biblioteca. Aqui vês:

- **Questionários de demonstração** (marcados «DEMO») — exemplos prontos com os quais podes testar já a aplicação.
- **Os teus questionários** (marcados «TEU») — os criados ou importados por ti.

Cada questionário aparece como um cartão colorido que mostra o título, a descrição e o **número de perguntas**. Em cada cartão tens opções para:

- **Jogar** — inicia a configuração do jogo (escolhes o modo).
- **Editar** — abre o questionário no editor.
- **Exportar** — guarda o questionário como ficheiro JSON.
- **Eliminar** — remove o questionário (só para os teus questionários; as demos não se podem eliminar).

> Um questionário de demonstração não se edita diretamente: se carregares em Editar numa demo, a aplicação faz-te uma **cópia** editável (marcada «✎»), para não estragares o original.

---

## 6. Criar um questionário

Carregas em **Criar** (ou **Editar** num questionário existente). O editor tem duas zonas: o cabeçalho do questionário e a lista de perguntas.

### Passo 1 — Título e descrição

1. No campo **Título**, escreve o nome do questionário (obrigatório, até 80–200 caracteres).
2. No campo **Descrição**, escreve opcionalmente um breve resumo (o que o questionário cobre).

### Passo 2 — Adicionar perguntas

- Um questionário novo começa com uma pergunta vazia.
- Carrega em **＋ Adicionar pergunta** para adicionar mais.
- Podes ter **até 100 perguntas** num questionário.

### Passo 3 — Configurar cada pergunta

Cada pergunta tem, no seu cabeçalho:

- Um **seletor de tipo** (Escolha múltipla / Verdadeiro-Falso / Resposta livre / Numérica) — ver a secção 7.
- Um **seletor de tempo** — quanto tempo os jogadores têm para responder: **5, 10, 20, 30, 60 ou 120 segundos**.
- Um **seletor de pontos** — **Padrão (1000)** ou **Duplo (2000)** para as perguntas mais difíceis.
- Botões **↑ / ↓** para reordenar a pergunta na lista.
- O botão **🗑** para eliminar a pergunta.

No corpo da pergunta escreves o **texto da pergunta** (até 200–500 caracteres) e configuras as respostas conforme o tipo.

### Passo 4 — Guardar

Carregas em **✓ Guardar**. A aplicação verifica primeiro:

- que o questionário tem título;
- que cada pergunta tem texto;
- que cada pergunta tem as respostas preenchidas corretamente para o seu tipo (ver abaixo).

Se faltar algo, recebes uma mensagem clara que te diz qual pergunta corrigir. Depois de guardar, o questionário aparece na biblioteca em «TEU».

> **Nota offline:** na edição HTML, os questionários são guardados no armazenamento local do navegador. Se limpares os dados do navegador ou usares outro navegador/dispositivo, deixas de os ver — por isso é importante **exportá-los** (secção 8) como cópia de segurança.

---

## 7. Os quatro tipos de perguntas

Escolhes o tipo no seletor do cabeçalho da pergunta.

### 7.1. Escolha múltipla (4 opções)

A pergunta clássica de opções múltiplas.

1. Escreve o texto da pergunta.
2. Preenche as opções de resposta (**de 2 a 4**).
3. Assinala **Correta** ao lado da opção certa (exatamente uma).
4. Com **✕** removes uma opção; com **Adicionar resposta** adicionas uma (até 4).

Durante o jogo, os jogadores carregam numa das pastilhas coloridas com formas (▲ ♦ ● ■).

### 7.2. Verdadeiro / Falso

Uma pergunta com apenas duas opções fixas: **Verdadeiro** e **Falso**.

1. Escreve a afirmação.
2. Assinala qual opção (Verdadeiro ou Falso) é a correta.

As etiquetas «Verdadeiro»/«Falso» são fixas e não se editam.

### 7.3. Resposta livre

O jogador **escreve** a resposta em vez de a escolher de uma lista.

1. Escreve a pergunta.
2. Em **A resposta correta (mostrada)**, escreve a resposta certa (a primeira variante é a que se mostra a todos na revelação como «a resposta correta»).
3. Opcionalmente, carrega em **Adicionar variante aceite** para adicionar sinónimos ou grafias alternativas (por ex. para «Bucareste» podes aceitar também «Bucuresti»). Podes ter até 6 variantes aceites.

**Como é pontuado:** a aplicação também aceita **pequenos erros de escrita** (correspondência aproximada) e **ignora os sinais diacríticos, as maiúsculas e a pontuação**. Por exemplo, «pariss» será aceite por «Paris». As palavras muito curtas (≤3 letras) são exigidas exatamente, para não haver correspondências acidentais.

> Dica: adiciona às variantes aceites todas as formas plausíveis que alguém possa escrever (abreviaturas, formas com/sem diacríticos, sinónimos).

### 7.4. Numérica («adivinha o número»)

O jogador escreve um **número**, e a pontuação aumenta quanto mais perto estiver da resposta.

1. Escreve a pergunta.
2. Em **O número correto**, põe o valor exato (por ex. `42`).
3. Em **Tolerância aceite (±)**, põe a que distância uma resposta pode estar e ainda contar como correta (por ex. `10` significa que qualquer valor entre 32 e 52 é correto).

**Como é pontuado:**

- A resposta **exata** obtém a pontuação máxima.
- Uma resposta **dentro do intervalo** obtém pontos proporcionais à proximidade: na margem do intervalo obtém metade, e quanto mais perto do exato, mais.
- Fora do intervalo = 0 pontos.
- **Tolerância 0** significa que só se aceita a resposta exata.
- Aceitam-se **decimais com vírgula** (por ex. `3,5`) e números negativos.
- No jogo ao vivo, a proximidade combina-se com a **velocidade** da resposta.

---

## 8. Importar e exportar

O formato é JSON, idêntico em ambas as edições.

### Exportar

1. Na biblioteca, carrega em **Exportar** no questionário desejado.
2. Descarrega-se um ficheiro `.json` com o nome do questionário.
3. Guarda-o como cópia de segurança ou envia-o a alguém.

### Importar

1. No ecrã inicial, carrega em **Importar**.
2. **Cola** o conteúdo JSON na caixa de texto (ou, conforme o caso, carrega o ficheiro).
3. Confirma. O questionário aparece na tua biblioteca.

A aplicação verifica o ficheiro ao importar: as perguntas inválidas são ignoradas, e um questionário não pode exceder as **100 perguntas** (o excedente é ignorado). O texto potencialmente perigoso é neutralizado automaticamente, por isso podes importar com segurança ficheiros recebidos de outros.

> Como ambas as edições usam o mesmo formato, um questionário exportado do HTML importa-se no `index.php` e vice-versa.

---

## 9. Os modos de jogo

Depois de carregares em **Jogar** num questionário, escolhes o modo:

| Modo | Onde | Descrição |
|-----|------|-----------|
| **Solo** | ambas as edições | Jogas sozinho, contra o relógio. |
| **À vez** | ambas as edições | Vários jogadores no **mesmo** dispositivo, por turnos. |
| **Ao vivo** | só servidor | Vários jogadores nos **seus dispositivos**, em sincronia (Kahoot). |
| **Trabalho autónomo** | só servidor | Cada um resolve ao seu ritmo, com um código. |

Na configuração tens também:

- **🔀 Baralhar perguntas e opções** — a ordem difere em cada jogo (ver 15).
- **👥 Modo por equipas** (só Ao vivo) — agrupas os jogadores em 2–4 equipas (ver 14).
- **A lista de jogadores** (em Solo/À vez) — os nomes dos participantes.

---

## 10. Jogo Solo

1. Escolhe um questionário e carrega em **Jogar**.
2. Escolhe o modo **Solo**.
3. Opcionalmente, escreve o teu nome (caso contrário apareces como «Tu»).
4. Carrega em **🚀 Começar o jogo**.
5. Vês uma **contagem decrescente**, depois a primeira pergunta.
6. Responde antes de o tempo acabar:
   - em Escolha múltipla / Verdadeiro-Falso: carrega na opção (ou nas teclas **1–4** / **Q W E R**);
   - em Resposta livre / Numérica: **escreve** a resposta e carrega em **Enviar** (ou **Enter**).
7. Depois de cada pergunta vês a **revelação**: se respondeste corretamente, quantos pontos obtiveste, a sequência de respostas certas e a pontuação atual.
8. No fim vês o **pódio** com a tua pontuação e uma pequena animação de confetes.

A pontuação depende da **correção** e da **velocidade** (as respostas rápidas obtêm mais pontos), mais um **bónus de sequência** por respostas certas consecutivas.

---

## 11. Jogo à vez

Perfeito para uma mesa de amigos com um só portátil/telemóvel.

1. Escolhe um questionário e carrega em **Jogar**, depois o modo **À vez**.
2. Adiciona os nomes dos jogadores com **Adicionar jogador** (até 8). Com **✕** retiras um jogador.
3. Carrega em **🚀 Começar o jogo**.
4. Para cada pergunta, a aplicação anuncia **de quem é a vez** («Passa a...»). O jogador atual carrega em **Estou pronto ▶** e responde.
5. Os outros **não** veem se a resposta é correta até à revelação (para não copiarem).
6. Depois de todos responderem, aparece a **revelação** com a resposta correta e quanto cada um obteve.
7. Entre perguntas aparece uma **classificação** que mostra quem sobe e quem desce.
8. No fim, o **pódio**.

> Passa efetivamente o dispositivo de um jogador para outro quando a aplicação te pedir.

---

## 12. Jogo ao vivo — o anfitrião

Este é o modo «Kahoot»: tu projetas as perguntas, e a sala responde a partir dos telemóveis. **Requer a edição servidor** e a **palavra-passe de anfitrião**.

### Passo 1 — Iniciar sessão como anfitrião

1. Abre o endereço da aplicação.
2. Inicia sessão como anfitrião com a **palavra-passe** recebida do administrador.
   - *No primeiro uso do servidor*, se ainda não houver palavra-passe, ser-te-á pedido para **definir** uma (no mínimo 6 caracteres). Ver também o *Manual do administrador*.

### Passo 2 — Criar a sessão de jogo

1. Escolhe o questionário e inicia um **jogo ao vivo**.
2. Opcionalmente, ativa o **🔀 baralhar** e/ou o **👥 modo por equipas** (2–4 equipas).
3. Confirma a criação. A aplicação gera um **código curto** (por ex. `ABCD`).

### Passo 3 — Convidar os participantes

No ecrã do anfitrião aparecem:

- O **código** de entrada;
- um **código QR** que os participantes leem;
- (conforme o caso) o link de entrada direto.

Os participantes abrem o endereço, introduzem o código (ou leem o QR) e entram na **sala de espera**.

### Passo 4 — Sala de espera

- Vês a lista de participantes que se juntam, em tempo real.
- Se ativaste as equipas, vês quantos membros tem cada equipa.
- Esperas que entrem todos, depois **inicias** o jogo.

### Passo 5 — Decorrer do jogo

Para cada pergunta:

1. O anfitrião mostra a pergunta e inicia o cronómetro.
2. Os participantes respondem a partir dos seus dispositivos.
3. Ao expirar (ou quando todos respondem), o anfitrião passa à **revelação**: a resposta correta, a distribuição das respostas, os mais rápidos.
4. Vês a **classificação** atualizada.
5. Passas à pergunta seguinte.

Durante o jogo, os participantes podem enviar **reações emoji** que flutuam no ecrã do anfitrião (ver 15).

### Passo 6 — Fim

Na última pergunta, o anfitrião mostra o **pódio** (top 3) e, se jogaste por equipas, a **classificação por equipas** com a equipa vencedora destacada. A partir daqui podes abrir os **relatórios** (secção 19).

---

## 13. Jogo ao vivo — o participante

Como participante **não precisas de conta nem de palavra-passe**.

1. Abre o endereço dado pelo anfitrião (ou lê o código QR).
2. Introduz o **código** do jogo.
3. Escolhe um **nome** (podes carregar em **🎲** para uma **alcunha gerada** automaticamente) e um **avatar** (emoji).
4. Se o jogo for por equipas, **escolhe a tua equipa** (botão colorido).
5. Entras na **sala de espera** e esperas que o anfitrião inicie.
6. Para cada pergunta, **responde** no teu telemóvel antes de o tempo acabar.
7. Depois de cada pergunta vês se pontuaste e a tua posição.
8. Podes enviar **reações emoji** do teu ecrã.
9. No fim vês **o teu lugar** (e o da tua equipa, se aplicável).

---

## 14. Modo por equipas

Disponível no **jogo ao vivo**. Agrupas os jogadores em equipas coloridas: **🔴 Vermelhos, 🔵 Azuis, 🟢 Verdes, 🟡 Amarelos**.

**Como anfitrião:**

1. Na configuração do jogo ao vivo, ativa o **👥 Modo por equipas**.
2. Escolhe o número de equipas: **2, 3 ou 4**.
3. Cria o jogo como de costume.

**Como funciona:**

- Ao entrar, cada participante **escolhe a sua equipa**.
- Cada um joga e pontua **individualmente**, mas as pontuações são **somadas por equipa**.
- Na sala de espera, o anfitrião vê o número de membros por equipa.
- No fim, além do pódio individual, aparece uma **classificação por equipas** com a equipa vencedora destacada.
- Cada jogador vê a sua equipa e a posição da equipa.

---

## 15. Reações, alcunhas, baralhar

### Reações emoji (jogo ao vivo)

Durante o jogo, os participantes têm uma barra com **👏 ❤️ 😮 😂 🔥 🎉**. Quando carregas numa, o emoji **flutua no ecrã do anfitrião**, criando ambiente. As reações têm um limite de ritmo para não haver spam.

### Gerador de alcunhas

Ao entrar num jogo ao vivo, o botão **🎲** ao lado do campo do nome propõe-te uma **alcunha aleatória** simpática (adjetivo + substantivo + número), útil se não quiseres usar o teu nome real.

### Baralhar (shuffle)

Na configuração, o interruptor **🔀 Baralhar perguntas e opções** faz com que:

- a ordem das **perguntas** difira em cada jogo;
- a ordem das **opções** nas perguntas de escolha múltipla seja baralhada.

Verdadeiro/Falso, a resposta livre e a numérica mantêm a sua estrutura. Funciona em todos os modos (Solo, À vez, Ao vivo).

> Baralhar é teu amigo quando reutilizas o mesmo questionário com grupos diferentes ou quando queres desencorajar a cópia entre vizinhos.

---

## 16. Trabalho autónomo

O modo «trabalho» (ao próprio ritmo, ao estilo do Quizizz) permite que cada participante resolva o questionário **ao seu próprio ritmo**, não em sincronia com o anfitrião. **Requer a edição servidor.**

**Como anfitrião / professor:**

1. Escolhe o questionário e cria uma sessão do tipo **trabalho autónomo**.
2. Distribui o **código** aos participantes.

**Como participante:**

1. Entras com o código.
2. Percorres as perguntas uma a uma, ao teu ritmo.
3. Recebes feedback depois de cada pergunta.
4. No fim vês o teu resultado.

A pontuação usa as mesmas regras do jogo (incluindo a proximidade para a numérica), mas sem a pressão da velocidade síncrona.

---

## 17. Atividades de audiência

Além dos questionários, a edição servidor oferece **atividades ao vivo de audiência** ao estilo do Slido/Mentimeter. O anfitrião cria uma atividade, recebe um código/QR, e o público contribui a partir dos telemóveis; os resultados aparecem **em tempo real** no ecrã do anfitrião.

Tipos disponíveis:

### Nuvem de palavras

O público envia palavras curtas; as palavras mais frequentes aparecem maiores. Bom para «descreve numa palavra...».

### Sondagem

O anfitrião define opções; o público vota; vês as percentagens ao vivo em barras coloridas.

### Perguntas e respostas (do público), com moderação

O público envia perguntas e pode **votar** (▲) nas dos outros, para que as populares subam. O anfitrião pode:

- **moderar** — se a moderação estiver ativa, as perguntas têm de ser **aprovadas** antes de serem visíveis para todos;
- marcar uma pergunta com uma **estrela** (★) ou como **respondida** (✓);
- **ocultar** ou **eliminar** perguntas.

### Avaliação / NPS

O público dá uma nota (estrelas numa escala de 5, ou 0–10 para o NPS). Vês a média / a pontuação NPS e a distribuição.

### Classificação

O público ordena algumas opções por preferência; vês a classificação média.

### Escalas (Likert)

Várias afirmações avaliadas numa escala (por ex. «discordo ↔ concordo»); vês a média de cada afirmação.

### 100 pontos

Cada participante distribui um orçamento de 100 pontos entre as opções; vês como se reparte o «orçamento» da audiência.

> **Aberto / Fechado:** o anfitrião pode **fechar** uma atividade para parar as novas contribuições, e depois reabri-la.

---

## 18. Apresentações (deck)

A edição servidor permite construir uma **apresentação** com vários **diapositivos**, sendo cada um uma atividade diferente (nuvem de palavras, sondagem, perguntas e respostas, avaliação, classificação, escalas, 100 pontos).

1. Crias uma apresentação, dás-lhe um **título**.
2. Adicionas diapositivos, escolhendo o tipo de cada um e configurando-o.
3. Na execução, o anfitrião **navega** entre os diapositivos com **◀ / ▶**, e o público vê sempre a atividade atual.

Útil para um workshop interativo inteiro executado a partir de uma única sessão.

---

## 19. Relatórios após o jogo

Depois de um jogo ao vivo, o anfitrião pode abrir **relatórios** com:

- resultados por participante (pontuação, respostas certas);
- estatísticas por pergunta (quantos responderam, percentagem de acerto, tempo médio);
- para escolha múltipla/verdadeiro-falso, a **distribuição** das respostas pelas opções.

Os relatórios podem ser **exportados** (CSV / JSON) ou **impressos**.

> Para as perguntas de resposta livre e numéricas, o relatório mostra as estatísticas gerais (quantos, acerto), sem gráfico de distribuição pelas opções.

---

## 20. A roda da fortuna

Uma ferramenta separada, **totalmente offline**, para escolhas aleatórias (quem responde, quem ganha, equipas ao acaso, etc.). Disponível em **ambas as edições**, através do botão **🎡 Roda** no ecrã inicial.

1. Carrega em **Roda**.
2. No painel da direita, escreve os **elementos**, um por linha (nomes, opções). Podes ter até 24.
3. Carrega em **🎯 Girar**. A roda gira e para num vencedor.
4. Opções:
   - **Remover o vencedor após cada sorteio** — útil para sortear por turnos, sem repetição (para num mínimo de 2 elementos);
   - **🔀 Baralhar** — reordena os elementos;
   - **↺ Repor** — volta à lista predefinida.

A lista fica memorizada localmente entre sessões.

---

## 21. Instalar como aplicação (PWA)

A edição servidor pode ser **instalada** como aplicação no telemóvel ou no computador (também funciona offline para os ecrãs já visitados).

- **No telemóvel (Chrome/Android):** abre o endereço, depois o menu do navegador → *Adicionar ao ecrã principal*.
- **No computador (Chrome/Edge):** aparece um ícone de instalação na barra de endereço.
- **No iPhone (Safari):** o botão *Partilhar* → *Adicionar ao ecrã principal*.

> O PWA requer que o servidor seja servido através de **HTTPS** (ver o *Manual do administrador*).

---

## 22. Conselhos e boas práticas

- **Testa antes.** Antes de um workshop com público, executa um jogo de ensaio com 2–3 dispositivos, para te certificares de que a rede, o código de entrada e cada tipo de pergunta funcionam nas tuas condições reais.
- **Exporta os teus questionários.** Sobretudo na edição offline, faz uma cópia de segurança através da exportação JSON — não confies só na memória do navegador.
- **Tempo adequado.** Põe 20 segundos para as perguntas normais, 5–10 para as curtas, 60–120 para as que exigem reflexão.
- **Resposta livre com generosidade.** Adiciona todas as variantes aceites plausíveis (sinónimos, formas com/sem diacríticos, abreviaturas).
- **Numérica com tolerância realista.** Escolhe uma tolerância que recompense a proximidade mas não torne correta qualquer resposta.
- **Baralhar** é teu amigo quando reutilizas o mesmo questionário com grupos diferentes.
- **Ecrã grande para o anfitrião.** No jogo ao vivo, projeta o ecrã do anfitrião; os participantes veem apenas os botões no telemóvel.

---

## 23. Resolução de problemas

**Não vejo os questionários que criei.**
Na edição offline, os questionários estão na memória desse navegador. Verifica que usas o mesmo navegador/dispositivo e que não limpaste os dados. No futuro, exporta-os como cópia de segurança.

**Importei um ficheiro e não aparece nada / aparecem menos perguntas.**
O ficheiro pode ser inválido ou exceder as 100 perguntas (o excedente é ignorado). As perguntas sem texto ou sem respostas válidas são saltadas automaticamente.

**No jogo ao vivo, os participantes não conseguem ligar-se.**
Verifica: estão na mesma rede / têm acesso ao endereço; introduziram o código corretamente; o jogo não foi já fechado. Pede ao administrador para confirmar que o servidor está iniciado e acessível.

**O botão de instalação (PWA) não aparece.**
O PWA requer HTTPS. Se o servidor estiver em HTTP simples, a instalação não está disponível (mas a aplicação funciona normalmente no navegador).

**O som não funciona.**
Verifica o interruptor de som na barra superior e o volume do dispositivo. Alguns navegadores exigem uma primeira interação (um clique) antes de reproduzir som.

**O campo de resposta livre não guarda o meu texto.**
Escreve e carrega em **Enviar** ou **Enter** antes de o tempo expirar. Se o tempo acabar, a pergunta é considerada falhada.

---

## 24. Privacidade

- A aplicação **não tem telemetria** e **não segue** os utilizadores.
- Na edição offline, **todos os dados ficam no teu dispositivo** (a memória do navegador e os ficheiros exportados).
- Na edição servidor, as sessões de jogo e os eventuais questionários são guardados no servidor do administrador (ver o *Manual do administrador* para detalhes sobre armazenamento e eliminação).
- Os participantes num jogo ao vivo **não precisam de conta**; usam apenas um nome à sua escolha (que também pode ser uma alcunha gerada).

---

*Undava — uma ferramenta de ficheiro único, offline-first, trade-free.
Para a instalação, a configuração e a manutenção, ver o **Manual do administrador**.*
</script>
<script type="text/markdown" id="manual-user-de">
# Undava — Benutzerhandbuch

*Eine vollständige Schritt-für-Schritt-Anleitung zum Erstellen von Quiz, zum Spielen im Unterricht und für Publikumsaktivitäten.*

---

## Inhalt

1. [Was Undava ist](#1-was-undava-ist)
2. [Die zwei Editionen und wann man welche nutzt](#2-die-zwei-editionen)
3. [Erste Schritte](#3-erste-schritte)
4. [Rundgang durch die Oberfläche](#4-rundgang)
5. [Die Quiz-Bibliothek](#5-bibliothek)
6. [Ein Quiz im Editor erstellen](#6-quiz-erstellen)
7. [Die vier Fragetypen](#7-fragetypen)
8. [Import und Export (JSON)](#8-import-export)
9. [Die Spielmodi](#9-spielmodi)
10. [Solo-Spiel — Schritt für Schritt](#10-solo-spiel)
11. [Reihum-Spiel — Schritt für Schritt](#11-reihum-spiel)
12. [Synchrones Live-Spiel — der Gastgeber](#12-live-gastgeber)
13. [Synchrones Live-Spiel — der Teilnehmer](#13-live-teilnehmer)
14. [Team-Modus](#14-team-modus)
15. [Reaktionen, Spitznamen, Mischen](#15-reaktionen-spitznamen-mischen)
16. [Selbstständige Aufgabe](#16-selbststaendig)
17. [Publikumsaktivitäten (Slido/Mentimeter)](#17-publikumsaktivitaeten)
18. [Präsentationen (Deck)](#18-praesentationen)
19. [Berichte nach dem Spiel](#19-berichte)
20. [Das Glücksrad](#20-gluecksrad)
21. [Als App installieren (PWA)](#21-app-installieren)
22. [Tipps und bewährte Praktiken](#22-tipps)
23. [Fehlerbehebung für Benutzer](#23-fehlerbehebung)
24. [Datenschutz](#24-datenschutz)

---

## 1. Was Undava ist

Undava ist ein Quizspiel im Geiste von Kahoot, kombiniert mit Publikumsaktivitäten im Stil von Slido/Mentimeter. Du kannst Quiz erstellen, sie allein oder mit einer Gruppe spielen (auf einem einzigen Gerät oder auf mehreren, synchron), und Live-Aktivitäten durchführen (Wortwolke, Umfragen, Fragen aus dem Publikum).

Die Anwendung ist **offline-first**, **ohne Telemetrie**, **ohne Pflichtkonten für die Teilnehmer** und **trade-free**. Deine Quiz gehören dir und werden lokal oder als JSON-Dateien gespeichert, die du überallhin verschieben kannst.

---

## 2. Die zwei Editionen

Die Anwendung kommt in zwei Formen. Beide heißen „Undava" und nutzen dasselbe **JSON-Format** für Quiz, sodass du sie frei zwischen beiden verschieben kannst.

### `quiz-fara-frontiere.html` — die vollständig offline-Edition

- Eine einzige HTML-Datei. Öffne sie per Doppelklick, **ohne Installation, ohne Internet, ohne Server**.
- Ideal für: Vorbereitung zu Hause, Einzelspiele, „Reihum"-Spiele (mehrere Spieler nacheinander, auf demselben Gerät), im Unterricht ohne Netzwerk.
- Enthält: die **4 Fragetypen**, die Spiele **Solo** und **Reihum**, das **Mischen** der Fragen und Optionen und das **Glücksrad**.
- Quiz werden im lokalen Speicher des Browsers gespeichert (und per JSON-Export).

### `index.php` — die Server-Edition (vollständig)

- Muss auf einem Server mit PHP gehostet werden (siehe das *Administratorhandbuch*). Sie öffnet sich **nicht** direkt per Doppelklick.
- Enthält alles, was die Offline-Edition hat, **plus** die Funktionen, die ein Netzwerk benötigen:
  - **Synchrones Live-Spiel** (mehrere Spieler, auf ihren Geräten, in Echtzeit);
  - **Selbstständige Aufgabe** (jeder löst im eigenen Tempo);
  - **Publikumsaktivitäten** (Wortwolke, Umfrage, moderierte Fragen & Antworten, Bewertung/NPS, Ranking, Skalen, 100 Punkte);
  - **Präsentationen** mit mehreren Aktivitäten auf Folien;
  - **Team-Modus**, **Emoji-Reaktionen**, **Beitrittscodes + QR**, **Berichte** nach dem Spiel, **Installation als App (PWA)**.

> **Kurz gesagt:** Wenn du nur allein oder mit Freunden auf einem Laptop erstellen und üben willst, reicht die HTML-Datei. Wenn du ein Live-Spiel mit vollem Raum willst, brauchst du die Server-Edition.

---

## 3. Erste Schritte

### A. Die Offline-Edition (HTML)

1. Besorge dir die Datei `quiz-fara-frontiere.html`.
2. Doppelklicke sie. Sie öffnet sich in deinem Standardbrowser.
3. Fertig — du bist auf dem Startbildschirm. Mehr ist nicht nötig.

### B. Die Server-Edition (index.php)

1. Der Administrator gibt dir eine **Webadresse** (z. B. `https://beispiel.com/quiz/`).
2. Öffne die Adresse im Browser.
3. Um Live-Spiele zu **erstellen und zu hosten**, brauchst du das **Gastgeber-Passwort** vom Administrator (siehe Abschnitt 12). Als **Teilnehmer** brauchst du kein Passwort.

### Die Sprache der Oberfläche

Die Anwendung spricht 7 Sprachen (Rumänisch, Englisch, Französisch, Italienisch, Spanisch, Portugiesisch, Deutsch). Wechsle die Sprache in der oberen rechten Ecke. Deine Wahl wird gemerkt.

---

## 4. Rundgang durch die Oberfläche

Der Startbildschirm hat, von oben nach unten:

- **Die obere Leiste** — das „Undava"-Logo (ein Klick darauf bringt dich zurück zur Startseite), den Sprachwähler und den Ton-Schalter.
- **Den Titel** und einen kurzen Untertitel.
- **Die großen Aktionsschaltflächen:**
  - **Spielen** — öffnet die Quiz-Bibliothek, in der du wählst, was du spielst.
  - **Erstellen** — öffnet den Editor für ein neues Quiz.
  - **Importieren** — du lädst ein Quiz aus einer JSON-Datei.
  - **Rad** — öffnet das Glücksrad (Zufallsauswahl).
- **Merkmale** als Abzeichen angezeigt: offline, kostenlos, privat, offen.

> Auf dem Handy ordnen sich die Schaltflächen vertikal an und der gesamte Inhalt passt sich an den kleinen Bildschirm an.

---

## 5. Die Quiz-Bibliothek

Du tippst auf **Spielen** und gelangst in die Bibliothek. Hier siehst du:

- **Demo-Quiz** (mit „DEMO" gekennzeichnet) — fertige Beispiele, mit denen du die Anwendung sofort testen kannst.
- **Deine Quiz** (mit „DEINS" gekennzeichnet) — die von dir erstellten oder importierten.

Jedes Quiz erscheint als farbige Karte, die den Titel, die Beschreibung und die **Anzahl der Fragen** zeigt. Auf jeder Karte hast du Optionen für:

- **Spielen** — startet die Spieleinrichtung (du wählst den Modus).
- **Bearbeiten** — öffnet das Quiz im Editor.
- **Exportieren** — speichert das Quiz als JSON-Datei.
- **Löschen** — entfernt das Quiz (nur für deine Quiz; Demos können nicht gelöscht werden).

> Ein Demo-Quiz wird nicht direkt bearbeitet: Wenn du bei einer Demo auf Bearbeiten tippst, erstellt dir die Anwendung eine bearbeitbare **Kopie** (mit „✎" gekennzeichnet), damit du das Original nicht beschädigst.

---

## 6. Ein Quiz erstellen

Du tippst auf **Erstellen** (oder **Bearbeiten** bei einem bestehenden Quiz). Der Editor hat zwei Bereiche: die Quiz-Kopfzeile und die Fragenliste.

### Schritt 1 — Titel und Beschreibung

1. Im Feld **Titel** schreibst du den Namen des Quiz (Pflicht, bis zu 80–200 Zeichen).
2. Im Feld **Beschreibung** schreibst du optional eine kurze Zusammenfassung (was das Quiz abdeckt).

### Schritt 2 — Fragen hinzufügen

- Ein neues Quiz beginnt mit einer leeren Frage.
- Tippe auf **＋ Frage hinzufügen**, um weitere hinzuzufügen.
- Du kannst **bis zu 100 Fragen** in einem Quiz haben.

### Schritt 3 — Jede Frage konfigurieren

Jede Frage hat in ihrer Kopfzeile:

- Einen **Typ-Wähler** (Multiple Choice / Wahr-Falsch / Freie Antwort / Numerisch) — siehe Abschnitt 7.
- Einen **Zeit-Wähler** — wie lange die Spieler zum Antworten haben: **5, 10, 20, 30, 60 oder 120 Sekunden**.
- Einen **Punkte-Wähler** — **Standard (1000)** oder **Doppelt (2000)** für schwierigere Fragen.
- Schaltflächen **↑ / ↓**, um die Frage in der Liste umzusortieren.
- Die Schaltfläche **🗑**, um die Frage zu löschen.

Im Fragekörper schreibst du den **Fragetext** (bis zu 200–500 Zeichen) und konfigurierst die Antworten je nach Typ.

### Schritt 4 — Speichern

Du tippst auf **✓ Speichern**. Die Anwendung prüft zuerst:

- dass das Quiz einen Titel hat;
- dass jede Frage einen Text hat;
- dass jede Frage ihre Antworten für ihren Typ korrekt ausgefüllt hat (siehe unten).

Wenn etwas fehlt, erhältst du eine klare Meldung, die dir sagt, welche Frage zu korrigieren ist. Nach dem Speichern erscheint das Quiz in der Bibliothek unter „DEINS".

> **Offline-Hinweis:** In der HTML-Edition werden Quiz im lokalen Speicher des Browsers gespeichert. Wenn du die Browserdaten löschst oder einen anderen Browser/ein anderes Gerät nutzt, siehst du sie nicht mehr — deshalb ist es wichtig, sie zu **exportieren** (Abschnitt 8) als Sicherung.

---

## 7. Die vier Fragetypen

Du wählst den Typ aus dem Wähler in der Fragekopfzeile.

### 7.1. Multiple Choice (4 Optionen)

Die klassische Frage mit mehreren Optionen.

1. Schreibe den Fragetext.
2. Fülle die Antwortoptionen aus (**2 bis 4**).
3. Hake **Richtig** neben der richtigen Option an (genau eine).
4. Mit **✕** entfernst du eine Option; mit **Antwort hinzufügen** fügst du eine hinzu (bis zu 4).

Während des Spiels tippen die Spieler auf eine der farbigen Formen-Pillen (▲ ♦ ● ■).

### 7.2. Wahr / Falsch

Eine Frage mit nur zwei festen Optionen: **Wahr** und **Falsch**.

1. Schreibe die Aussage.
2. Hake an, welche Option (Wahr oder Falsch) die richtige ist.

Die Beschriftungen „Wahr"/„Falsch" sind fest und werden nicht bearbeitet.

### 7.3. Freie Antwort

Der Spieler **schreibt** die Antwort, statt sie aus einer Liste zu wählen.

1. Schreibe die Frage.
2. In **Die richtige Antwort (angezeigt)** schreibst du die richtige Antwort (die erste Variante ist die, die allen bei der Auflösung als „die richtige Antwort" gezeigt wird).
3. Optional tippst du auf **Akzeptierte Variante hinzufügen**, um Synonyme oder alternative Schreibweisen hinzuzufügen (z. B. für „Bukarest" kannst du auch „Bucuresti" akzeptieren). Du kannst bis zu 6 akzeptierte Varianten haben.

**Wie es bewertet wird:** Die Anwendung akzeptiert auch **kleine Tippfehler** (ungefähre Übereinstimmung) und **ignoriert diakritische Zeichen, Groß-/Kleinschreibung und Interpunktion**. Zum Beispiel wird „pariss" für „Paris" akzeptiert. Sehr kurze Wörter (≤3 Buchstaben) werden genau verlangt, damit keine zufälligen Übereinstimmungen auftreten.

> Tipp: Füge den akzeptierten Varianten alle plausiblen Formen hinzu, die jemand schreiben könnte (Abkürzungen, Formen mit/ohne diakritische Zeichen, Synonyme).

### 7.4. Numerisch („errate die Zahl")

Der Spieler schreibt eine **Zahl**, und die Punktzahl steigt, je näher sie an der Antwort ist.

1. Schreibe die Frage.
2. In **Die richtige Zahl** gibst du den genauen Wert an (z. B. `42`).
3. In **Akzeptierte Toleranz (±)** gibst du an, wie weit eine Antwort entfernt sein darf und trotzdem als richtig zählt (z. B. `10` bedeutet, dass alles zwischen 32 und 52 richtig ist).

**Wie es bewertet wird:**

- Die **genaue** Antwort erhält die maximale Punktzahl.
- Eine Antwort **innerhalb des Bereichs** erhält Punkte proportional zur Nähe: am Rand des Bereichs erhält sie die Hälfte, und je näher am Genauen, desto mehr.
- Außerhalb des Bereichs = 0 Punkte.
- **Toleranz 0** bedeutet, dass nur die genaue Antwort akzeptiert wird.
- **Dezimalzahlen mit Komma** (z. B. `3,5`) und negative Zahlen werden akzeptiert.
- Im Live-Spiel wird die Nähe mit der **Geschwindigkeit** der Antwort kombiniert.

---

## 8. Import und Export

Das Format ist JSON, in beiden Editionen identisch.

### Export

1. In der Bibliothek tippst du bei dem gewünschten Quiz auf **Exportieren**.
2. Eine `.json`-Datei mit dem Namen des Quiz wird heruntergeladen.
3. Bewahre sie als Sicherung auf oder sende sie jemandem.

### Import

1. Auf dem Startbildschirm tippst du auf **Importieren**.
2. **Füge** den JSON-Inhalt in das Textfeld ein (oder lade gegebenenfalls die Datei).
3. Bestätige. Das Quiz erscheint in deiner Bibliothek.

Die Anwendung prüft die Datei beim Import: ungültige Fragen werden ignoriert, und ein Quiz darf **100 Fragen** nicht überschreiten (der Überschuss wird ignoriert). Potenziell gefährlicher Text wird automatisch neutralisiert, sodass du von anderen erhaltene Dateien sicher importieren kannst.

> Da beide Editionen dasselbe Format nutzen, wird ein aus HTML exportiertes Quiz in `index.php` importiert und umgekehrt.

---

## 9. Die Spielmodi

Nachdem du bei einem Quiz auf **Spielen** getippt hast, wählst du den Modus:

| Modus | Wo | Beschreibung |
|-----|------|-----------|
| **Solo** | beide Editionen | Du spielst allein, gegen die Uhr. |
| **Reihum** | beide Editionen | Mehrere Spieler auf **demselben** Gerät, nacheinander. |
| **Live** | nur Server | Mehrere Spieler auf **ihren Geräten**, synchron (Kahoot). |
| **Selbstständige Aufgabe** | nur Server | Jeder löst im eigenen Tempo, mit einem Code. |

Bei der Einrichtung hast du außerdem:

- **🔀 Fragen und Optionen mischen** — die Reihenfolge unterscheidet sich bei jedem Spiel (siehe 15).
- **👥 Team-Modus** (nur Live) — du gruppierst die Spieler in 2–4 Teams (siehe 14).
- **Die Spielerliste** (bei Solo/Reihum) — die Namen der Teilnehmer.

---

## 10. Solo-Spiel

1. Wähle ein Quiz und tippe auf **Spielen**.
2. Wähle den Modus **Solo**.
3. Optional schreibst du deinen Namen (sonst erscheinst du als „Du").
4. Tippe auf **🚀 Spiel starten**.
5. Du siehst einen **Countdown**, dann die erste Frage.
6. Antworte, bevor die Zeit abläuft:
   - bei Multiple Choice / Wahr-Falsch: tippe auf die Option (oder die Tasten **1–4** / **Q W E R**);
   - bei Freie Antwort / Numerisch: **schreibe** die Antwort und tippe auf **Senden** (oder **Enter**).
7. Nach jeder Frage siehst du die **Auflösung**: ob du richtig geantwortet hast, wie viele Punkte du bekommen hast, die Serie richtiger Antworten und die aktuelle Punktzahl.
8. Am Ende siehst du das **Podium** mit deiner Punktzahl und einer kleinen Konfetti-Animation.

Die Punktzahl hängt von der **Richtigkeit** und der **Geschwindigkeit** ab (schnelle Antworten bekommen mehr Punkte), plus einem **Serienbonus** für aufeinanderfolgende richtige Antworten.

---

## 11. Reihum-Spiel

Perfekt für einen Tisch voller Freunde mit einem einzigen Laptop/Handy.

1. Wähle ein Quiz und tippe auf **Spielen**, dann auf den Modus **Reihum**.
2. Füge die Namen der Spieler mit **Spieler hinzufügen** hinzu (bis zu 8). Mit **✕** entfernst du einen Spieler.
3. Tippe auf **🚀 Spiel starten**.
4. Für jede Frage kündigt die Anwendung an, **wer an der Reihe ist** („Weiter an..."). Der aktuelle Spieler tippt auf **Ich bin bereit ▶** und antwortet.
5. Die anderen sehen **nicht**, ob die Antwort richtig ist, bis zur Auflösung (damit sie nicht abschreiben).
6. Nachdem alle geantwortet haben, erscheint die **Auflösung** mit der richtigen Antwort und wie viel jeder bekommen hat.
7. Zwischen den Fragen erscheint eine **Rangliste**, die zeigt, wer aufsteigt und wer absteigt.
8. Am Ende das **Podium**.

> Reiche das Gerät tatsächlich von einem Spieler zum anderen weiter, wenn die Anwendung dich dazu auffordert.

---

## 12. Live-Spiel — der Gastgeber

Das ist der „Kahoot"-Modus: Du projizierst die Fragen, und der Raum antwortet von den Handys. **Erfordert die Server-Edition** und das **Gastgeber-Passwort**.

### Schritt 1 — Als Gastgeber anmelden

1. Öffne die Adresse der Anwendung.
2. Melde dich als Gastgeber mit dem vom Administrator erhaltenen **Passwort** an.
   - *Bei der ersten Nutzung des Servers*, wenn es noch kein Passwort gibt, wirst du aufgefordert, eines **festzulegen** (mindestens 6 Zeichen). Siehe auch das *Administratorhandbuch*.

### Schritt 2 — Die Spielsitzung erstellen

1. Wähle das Quiz und starte ein **Live-Spiel**.
2. Optional aktivierst du das **🔀 Mischen** und/oder den **👥 Team-Modus** (2–4 Teams).
3. Bestätige die Erstellung. Die Anwendung generiert einen **kurzen Code** (z. B. `ABCD`).

### Schritt 3 — Die Teilnehmer einladen

Auf dem Gastgeberbildschirm erscheinen:

- Der Beitritts-**Code**;
- ein **QR-Code**, den die Teilnehmer scannen;
- (gegebenenfalls) der direkte Beitrittslink.

Die Teilnehmer öffnen die Adresse, geben den Code ein (oder scannen den QR) und betreten die **Lobby**.

### Schritt 4 — Lobby

- Du siehst die Liste der beitretenden Teilnehmer, in Echtzeit.
- Wenn du Teams aktiviert hast, siehst du, wie viele Mitglieder jedes Team hat.
- Du wartest, bis alle beigetreten sind, dann **startest** du das Spiel.

### Schritt 5 — Ablauf des Spiels

Für jede Frage:

1. Der Gastgeber zeigt die Frage an und startet den Timer.
2. Die Teilnehmer antworten von ihren Geräten.
3. Beim Ablauf (oder wenn alle antworten) geht der Gastgeber zur **Auflösung** über: die richtige Antwort, die Verteilung der Antworten, die Schnellsten.
4. Du siehst die aktualisierte **Rangliste**.
5. Du gehst zur nächsten Frage über.

Während des Spiels können die Teilnehmer **Emoji-Reaktionen** senden, die über den Gastgeberbildschirm schweben (siehe 15).

### Schritt 6 — Ende

Bei der letzten Frage zeigt der Gastgeber das **Podium** (Top 3) und, wenn du in Teams gespielt hast, die **Team-Rangliste** mit dem hervorgehobenen Siegerteam. Von hier aus kannst du die **Berichte** öffnen (Abschnitt 19).

---

## 13. Live-Spiel — der Teilnehmer

Als Teilnehmer **brauchst du kein Konto und kein Passwort**.

1. Öffne die vom Gastgeber gegebene Adresse (oder scanne den QR-Code).
2. Gib den **Code** des Spiels ein.
3. Wähle einen **Namen** (du kannst auf **🎲** tippen für einen automatisch **generierten Spitznamen**) und einen **Avatar** (Emoji).
4. Wenn das Spiel in Teams ist, **wähle dein Team** (farbige Schaltfläche).
5. Du betrittst die **Lobby** und wartest, bis der Gastgeber startet.
6. Für jede Frage **antwortest** du auf deinem Handy, bevor die Zeit abläuft.
7. Nach jeder Frage siehst du, ob du gepunktet hast und deine Position.
8. Du kannst **Emoji-Reaktionen** von deinem Bildschirm senden.
9. Am Ende siehst du **deinen Platz** (und den deines Teams, falls zutreffend).

---

## 14. Team-Modus

Verfügbar im **Live-Spiel**. Du gruppierst die Spieler in farbige Teams: **🔴 Rote, 🔵 Blaue, 🟢 Grüne, 🟡 Gelbe**.

**Als Gastgeber:**

1. Bei der Einrichtung des Live-Spiels aktivierst du den **👥 Team-Modus**.
2. Wähle die Anzahl der Teams: **2, 3 oder 4**.
3. Erstelle das Spiel wie gewohnt.

**Wie es funktioniert:**

- Beim Beitritt **wählt jeder Teilnehmer sein Team**.
- Jeder spielt und punktet **individuell**, aber die Punkte werden **pro Team summiert**.
- In der Lobby sieht der Gastgeber die Anzahl der Mitglieder pro Team.
- Am Ende erscheint neben dem individuellen Podium eine **Team-Rangliste** mit dem hervorgehobenen Siegerteam.
- Jeder Spieler sieht sein Team und die Position des Teams.

---

## 15. Reaktionen, Spitznamen, Mischen

### Emoji-Reaktionen (Live-Spiel)

Während des Spiels haben die Teilnehmer eine Leiste mit **👏 ❤️ 😮 😂 🔥 🎉**. Wenn du auf eine tippst, **schwebt das Emoji über den Gastgeberbildschirm** und schafft Atmosphäre. Die Reaktionen sind im Rhythmus begrenzt, damit es keinen Spam gibt.

### Spitznamen-Generator

Beim Beitritt zu einem Live-Spiel schlägt dir die Schaltfläche **🎲** neben dem Namensfeld einen freundlichen **zufälligen Spitznamen** vor (Adjektiv + Substantiv + Zahl), nützlich, wenn du deinen echten Namen nicht nutzen willst.

### Mischen (Shuffle)

Bei der Einrichtung bewirkt der Schalter **🔀 Fragen und Optionen mischen**, dass:

- die Reihenfolge der **Fragen** sich bei jedem Spiel unterscheidet;
- die Reihenfolge der **Optionen** bei Multiple-Choice-Fragen gemischt wird.

Wahr/Falsch, die freie Antwort und die numerische behalten ihre Struktur. Es funktioniert in allen Modi (Solo, Reihum, Live).

> Das Mischen ist dein Freund, wenn du dasselbe Quiz mit verschiedenen Gruppen wiederverwendest oder wenn du das Abschreiben zwischen Nachbarn erschweren willst.

---

## 16. Selbstständige Aufgabe

Der „Aufgaben"-Modus (im eigenen Tempo, im Stil von Quizizz) ermöglicht jedem Teilnehmer, das Quiz **im eigenen Tempo** zu lösen, nicht synchron mit dem Gastgeber. **Erfordert die Server-Edition.**

**Als Gastgeber / Lehrer:**

1. Wähle das Quiz und erstelle eine Sitzung vom Typ **selbstständige Aufgabe**.
2. Verteile den **Code** an die Teilnehmer.

**Als Teilnehmer:**

1. Du trittst mit dem Code bei.
2. Du gehst die Fragen eine nach der anderen durch, in deinem Tempo.
3. Du erhältst nach jeder Frage Rückmeldung.
4. Am Ende siehst du dein Ergebnis.

Die Bewertung nutzt dieselben Regeln wie das Spiel (einschließlich der Nähe beim Numerischen), aber ohne den Druck der synchronen Geschwindigkeit.

---

## 17. Publikumsaktivitäten

Neben den Quiz bietet die Server-Edition **Live-Publikumsaktivitäten** im Stil von Slido/Mentimeter. Der Gastgeber erstellt eine Aktivität, erhält einen Code/QR, und das Publikum trägt von den Handys bei; die Ergebnisse erscheinen **in Echtzeit** auf dem Gastgeberbildschirm.

Verfügbare Typen:

### Wortwolke

Das Publikum sendet kurze Wörter; die häufigeren Wörter erscheinen größer. Gut für „beschreibe in einem Wort...".

### Umfrage

Der Gastgeber definiert Optionen; das Publikum stimmt ab; du siehst die Prozentsätze live auf farbigen Balken.

### Fragen & Antworten (aus dem Publikum), mit Moderation

Das Publikum sendet Fragen und kann die anderer **hochvoten** (▲), damit die beliebten aufsteigen. Der Gastgeber kann:

- **moderieren** — wenn die Moderation aktiv ist, müssen Fragen **genehmigt** werden, bevor sie für alle sichtbar sind;
- eine Frage mit einem **Stern** (★) oder als **beantwortet** (✓) markieren;
- Fragen **ausblenden** oder **löschen**.

### Bewertung / NPS

Das Publikum gibt eine Note (Sterne auf einer Skala von 5, oder 0–10 für NPS). Du siehst den Durchschnitt / den NPS-Wert und die Verteilung.

### Ranking

Das Publikum ordnet einige Optionen nach Vorliebe; du siehst das durchschnittliche Ranking.

### Skalen (Likert)

Mehrere Aussagen, auf einer Skala bewertet (z. B. „Ablehnung ↔ Zustimmung"); du siehst den Durchschnitt jeder Aussage.

### 100 Punkte

Jeder Teilnehmer verteilt ein Budget von 100 Punkten auf die Optionen; du siehst, wie sich das „Budget" des Publikums aufteilt.

> **Offen / Geschlossen:** Der Gastgeber kann eine Aktivität **schließen**, um neue Beiträge zu stoppen, und sie dann wieder öffnen.

---

## 18. Präsentationen (Deck)

Die Server-Edition ermöglicht den Bau einer **Präsentation** mit mehreren **Folien**, wobei jede eine andere Aktivität ist (Wortwolke, Umfrage, Fragen & Antworten, Bewertung, Ranking, Skalen, 100 Punkte).

1. Du erstellst eine Präsentation, gibst ihr einen **Titel**.
2. Du fügst Folien hinzu, wählst den Typ jeder einzelnen und konfigurierst sie.
3. Bei der Ausführung **navigiert** der Gastgeber zwischen den Folien mit **◀ / ▶**, und das Publikum sieht immer die aktuelle Aktivität.

Nützlich für einen ganzen interaktiven Workshop, der aus einer einzigen Sitzung durchgeführt wird.

---

## 19. Berichte nach dem Spiel

Nach einem Live-Spiel kann der Gastgeber **Berichte** öffnen mit:

- Ergebnissen pro Teilnehmer (Punktzahl, richtige Antworten);
- Statistiken pro Frage (wie viele geantwortet haben, Prozentsatz der Richtigkeit, durchschnittliche Zeit);
- für Multiple Choice/Wahr-Falsch, die **Verteilung** der Antworten auf die Optionen.

Die Berichte können **exportiert** (CSV / JSON) oder **gedruckt** werden.

> Bei Fragen mit freier Antwort und numerischen zeigt der Bericht die allgemeinen Statistiken (wie viele, Richtigkeit), ohne Verteilungsdiagramm auf die Optionen.

---

## 20. Das Glücksrad

Ein separates Werkzeug, **vollständig offline**, für zufällige Auswahlen (wer antwortet, wer gewinnt, zufällige Teams usw.). Verfügbar in **beiden Editionen**, über die Schaltfläche **🎡 Rad** auf dem Startbildschirm.

1. Tippe auf **Rad**.
2. Im rechten Bereich schreibst du die **Elemente**, eines pro Zeile (Namen, Optionen). Du kannst bis zu 24 haben.
3. Tippe auf **🎯 Drehen**. Das Rad dreht sich und hält bei einem Gewinner an.
4. Optionen:
   - **Gewinner nach jeder Ziehung entfernen** — nützlich, um nacheinander zu ziehen, ohne Wiederholung (hält bei mindestens 2 Elementen an);
   - **🔀 Mischen** — ordnet die Elemente um;
   - **↺ Zurücksetzen** — kehrt zur Standardliste zurück.

Die Liste wird lokal zwischen den Sitzungen gemerkt.

---

## 21. Als App installieren (PWA)

Die Server-Edition kann als App auf dem Handy oder Desktop **installiert** werden (sie funktioniert auch offline für bereits besuchte Bildschirme).

- **Auf dem Handy (Chrome/Android):** öffne die Adresse, dann das Browsermenü → *Zum Startbildschirm hinzufügen*.
- **Auf dem Desktop (Chrome/Edge):** ein Installationssymbol erscheint in der Adressleiste.
- **Auf dem iPhone (Safari):** die Schaltfläche *Teilen* → *Zum Home-Bildschirm*.

> Das PWA erfordert, dass der Server über **HTTPS** ausgeliefert wird (siehe das *Administratorhandbuch*).

---

## 22. Tipps und bewährte Praktiken

- **Teste vorher.** Vor einem Workshop mit Publikum führe ein Testspiel mit 2–3 Geräten durch, um sicherzustellen, dass das Netzwerk, der Beitrittscode und jeder Fragetyp unter deinen realen Bedingungen funktionieren.
- **Exportiere deine Quiz.** Besonders in der Offline-Edition sichere per JSON-Export — verlasse dich nicht nur auf den Speicher des Browsers.
- **Passende Zeit.** Stelle 20 Sekunden für normale Fragen ein, 5–10 für kurze, 60–120 für die, die Nachdenken erfordern.
- **Freie Antwort großzügig.** Füge alle plausiblen akzeptierten Varianten hinzu (Synonyme, Formen mit/ohne diakritische Zeichen, Abkürzungen).
- **Numerisch mit realistischer Toleranz.** Wähle eine Toleranz, die die Nähe belohnt, aber nicht jede Antwort richtig macht.
- **Das Mischen** ist dein Freund, wenn du dasselbe Quiz mit verschiedenen Gruppen wiederverwendest.
- **Großer Bildschirm für den Gastgeber.** Im Live-Spiel projiziere den Gastgeberbildschirm; die Teilnehmer sehen nur die Schaltflächen auf ihrem Handy.

---

## 23. Fehlerbehebung

**Ich sehe die Quiz, die ich erstellt habe, nicht.**
In der Offline-Edition befinden sich die Quiz im Speicher dieses Browsers. Prüfe, dass du denselben Browser/dasselbe Gerät nutzt und die Daten nicht gelöscht hast. In Zukunft exportiere sie als Sicherung.

**Ich habe eine Datei importiert und es erscheint nichts / es erscheinen weniger Fragen.**
Die Datei kann ungültig sein oder 100 Fragen überschreiten (der Überschuss wird ignoriert). Fragen ohne Text oder ohne gültige Antworten werden automatisch übersprungen.

**Im Live-Spiel können sich die Teilnehmer nicht verbinden.**
Prüfe: Sie sind im selben Netzwerk / haben Zugang zur Adresse; sie haben den Code korrekt eingegeben; das Spiel wurde nicht bereits geschlossen. Bitte den Administrator zu bestätigen, dass der Server gestartet und erreichbar ist.

**Die Installationsschaltfläche (PWA) erscheint nicht.**
Das PWA erfordert HTTPS. Wenn der Server auf einfachem HTTP läuft, ist die Installation nicht verfügbar (aber die Anwendung funktioniert normal im Browser).

**Der Ton funktioniert nicht.**
Prüfe den Ton-Schalter in der oberen Leiste und die Lautstärke des Geräts. Einige Browser erfordern eine erste Interaktion (einen Klick), bevor sie Ton abspielen.

**Das Feld für die freie Antwort behält meinen Text nicht.**
Schreibe und tippe auf **Senden** oder **Enter**, bevor die Zeit abläuft. Wenn die Zeit endet, gilt die Frage als verpasst.

---

## 24. Datenschutz

- Die Anwendung hat **keine Telemetrie** und **verfolgt** die Benutzer **nicht**.
- In der Offline-Edition **bleiben alle Daten auf deinem Gerät** (der Speicher des Browsers und die exportierten Dateien).
- In der Server-Edition werden die Spielsitzungen und etwaige Quiz auf dem Server des Administrators aufbewahrt (siehe das *Administratorhandbuch* für Details zu Speicherung und Löschung).
- Die Teilnehmer eines Live-Spiels **brauchen kein Konto**; sie nutzen nur einen selbst gewählten Namen (der auch ein generierter Spitzname sein kann).

---

*Undava — ein Single-File-, Offline-first-, Trade-free-Werkzeug.
Für Installation, Konfiguration und Wartung siehe das **Administratorhandbuch**.*
</script>
<script type="text/markdown" id="manual-user">
# Undava — Manual de utilizator

*Ghid complet, pas cu pas, pentru crearea quiz-urilor, jocul în clasă și activitățile de audiență.*

---

## Cuprins

1. [Ce este Undava](#1-ce-este-quiz-fără-frontiere)
2. [Cele două ediții și când folosești fiecare](#2-cele-două-ediții)
3. [Primii pași](#3-primii-pași)
4. [Turul interfeței](#4-turul-interfeței)
5. [Biblioteca de quiz-uri](#5-biblioteca-de-quiz-uri)
6. [Crearea unui quiz în editor](#6-crearea-unui-quiz-în-editor)
7. [Cele patru tipuri de întrebări](#7-cele-patru-tipuri-de-întrebări)
8. [Import și export (JSON)](#8-import-și-export)
9. [Modurile de joc](#9-modurile-de-joc)
10. [Joc Solo — pas cu pas](#10-joc-solo)
11. [Joc Hotseat — pas cu pas](#11-joc-hotseat)
12. [Joc live sincron — gazda (host)](#12-joc-live-sincron--gazda)
13. [Joc live sincron — participantul](#13-joc-live-sincron--participantul)
14. [Modul pe echipe](#14-modul-pe-echipe)
15. [Reacții, porecle, amestecare](#15-reacții-porecle-amestecare)
16. [Temă autonomă (self-paced)](#16-temă-autonomă)
17. [Activități de audiență (Slido/Mentimeter)](#17-activități-de-audiență)
18. [Prezentări (deck)](#18-prezentări-deck)
19. [Rapoarte după joc](#19-rapoarte-după-joc)
20. [Roata norocului](#20-roata-norocului)
21. [Instalare ca aplicație (PWA)](#21-instalare-ca-aplicație-pwa)
22. [Sfaturi și bune practici](#22-sfaturi-și-bune-practici)
23. [Depanare pentru utilizatori](#23-depanare)
24. [Confidențialitate](#24-confidențialitate)

---

## 1. Ce este Undava

Undava este un joc de quiz în spiritul Kahoot, combinat cu activități de
audiență în stil Slido/Mentimeter. Poți crea quiz-uri, le poți juca singur sau cu un
grup (pe un singur dispozitiv sau pe mai multe, sincron), și poți rula activități live
(nor de cuvinte, sondaje, întrebări din public).

Aplicația este **offline-first**, **fără telemetrie**, **fără conturi obligatorii pentru
participanți** și **trade-free**. Quiz-urile tale îți aparțin și se salvează local sau ca
fișiere JSON pe care le poți muta oriunde.

---

## 2. Cele două ediții

Aplicația vine în două forme. Ambele se numesc „Undava" și folosesc **același
format JSON** pentru quiz-uri, deci le poți muta liber între ele.

### `quiz-fara-frontiere.html` — ediția pur offline

- Un singur fișier HTML. Se deschide cu dublu-clic, **fără instalare, fără internet, fără
  server**.
- Ideală pentru: pregătire acasă, jocuri individuale, jocuri „hotseat" (mai mulți jucători
  pe rând, pe același dispozitiv), la clasă fără rețea.
- Conține: cele **4 tipuri de întrebări**, jocul **Solo** și **Hotseat**, **amestecarea**
  întrebărilor și variantelor, și **Roata norocului**.
- Quiz-urile se salvează în memoria locală a browserului (și prin export JSON).

### `index.php` — ediția server (completă)

- Trebuie găzduită de un server cu PHP (vezi *Manualul administratorului*). **Nu** se
  deschide direct cu dublu-clic.
- Conține tot ce are ediția offline **plus** funcțiile care au nevoie de rețea:
  - **Joc live sincron** (mai mulți jucători, pe dispozitivele lor, în timp real);
  - **Temă autonomă** (fiecare rezolvă în ritmul lui);
  - **Activități de audiență** (nor de cuvinte, sondaj, Q&A moderat, rating/NPS, ranking,
    scale, 100 de puncte);
  - **Prezentări** cu mai multe activități pe slide-uri;
  - **Mod pe echipe**, **reacții emoji**, **coduri + QR de intrare**, **rapoarte** după joc,
    **instalare ca aplicație (PWA)**.

> **Pe scurt:** dacă vrei doar să creezi și să exersezi singur sau cu prietenii pe un
> laptop, ajunge fișierul HTML. Dacă vrei un joc live cu sala plină, ai nevoie de ediția
> server.

---

## 3. Primii pași

### A. Ediția offline (HTML)

1. Obține fișierul `quiz-fara-frontiere.html`.
2. Fă dublu-clic pe el. Se deschide în browserul tău implicit.
3. Gata — ești pe ecranul principal. Nu e nevoie de nimic altceva.

### B. Ediția server (index.php)

1. Administratorul îți dă o **adresă web** (de ex. `https://exemplu.ro/quiz/`).
2. Deschide adresa în browser.
3. Pentru a **crea și găzdui** jocuri live, vei avea nevoie de **parola de gazdă** de la
   administrator (vezi secțiunea 12). Ca **participant**, nu ai nevoie de parolă.

### Limba interfeței

Aplicația este bilingvă (română / engleză). Comută limba din colțul dreapta-sus, cu
butoanele **RO** / **EN**. Alegerea se ține minte.

---

## 4. Turul interfeței

Ecranul principal (Acasă) are, de sus în jos:

- **Bara de sus** — logo-ul „Undava" (clic pe el te readuce Acasă),
  comutatorul de limbă **RO / EN**, și comutatorul de sunet.
- **Titlul** și un scurt subtitlu.
- **Butoanele mari de acțiune:**
  - **Joacă** — deschide biblioteca de quiz-uri, de unde alegi ce joci.
  - **Creează** — deschide editorul pentru un quiz nou.
  - **Importă** — încarci un quiz dintr-un fișier JSON.
  - **Roata** — deschide Roata norocului (selector aleatoriu).
- **Caracteristici** afișate ca insigne: offline, gratuit, privat, open.

> Pe telefon, butoanele se aranjează pe verticală și tot conținutul se adaptează la
> ecranul mic.

---

## 5. Biblioteca de quiz-uri

Apeși **Joacă** și ajungi în bibliotecă. Aici vezi:

- **Quiz-uri demonstrative** (marcate „DEMO") — exemple gata făcute cu care poți testa
  imediat aplicația.
- **Quiz-urile tale** (marcate „AL TĂU") — cele create sau importate de tine.

Fiecare quiz apare ca un card colorat care arată titlul, descrierea și **numărul de
întrebări**. Pe fiecare card ai opțiuni pentru:

- **Redare / Joacă** — pornește configurarea jocului (alegi modul).
- **Editează** — deschide quiz-ul în editor.
- **Exportă** — salvează quiz-ul ca fișier JSON.
- **Șterge** — elimină quiz-ul (doar pentru quiz-urile tale; demo-urile nu se pot șterge).

> Un quiz demonstrativ nu se editează direct: dacă apeși Editează pe un demo, aplicația
> îți face o **copie** editabilă (marcată cu „✎"), ca să nu strici originalul.

---

## 6. Crearea unui quiz în editor

Apeși **Creează** (sau **Editează** pe un quiz existent). Editorul are două zone: antetul
quiz-ului și lista de întrebări.

### Pasul 1 — Titlul și descrierea

1. În câmpul **Titlu**, scrie numele quiz-ului (obligatoriu, până la 80–200 de caractere).
2. În câmpul **Descriere**, scrie opțional un scurt rezumat (ce acoperă quiz-ul).

### Pasul 2 — Adăugarea întrebărilor

- Quiz-ul nou pornește cu o întrebare goală.
- Apeși **＋ Adaugă întrebare** ca să adaugi altele.
- Poți avea **până la 100 de întrebări** într-un quiz.

### Pasul 3 — Configurarea fiecărei întrebări

Fiecare întrebare are, în antetul ei:

- Un **selector de tip** (Grilă / Adevărat-Fals / Răspuns liber / Numeric) — vezi
  secțiunea 7.
- Un **selector de timp** — cât timp au jucătorii să răspundă: **5, 10, 20, 30, 60 sau
  120 de secunde**.
- Un **selector de puncte** — **Standard (1000)** sau **Dublu (2000)** pentru întrebările
  mai grele.
- Butoane **↑ / ↓** pentru a reordona întrebarea în listă.
- Butonul **🗑** pentru a șterge întrebarea.

În corpul întrebării scrii **textul întrebării** (până la 200–500 de caractere) și
configurezi răspunsurile în funcție de tip.

### Pasul 4 — Salvarea

Apeși **✓ Salvează**. Aplicația verifică întâi:

- că quiz-ul are titlu;
- că fiecare întrebare are text;
- că fiecare întrebare are răspunsurile completate corect pentru tipul ei (vezi mai jos).

Dacă ceva lipsește, primești un mesaj clar care îți spune ce întrebare trebuie corectată.
După salvare, quiz-ul apare în bibliotecă la „AL TĂU".

> **Notă offline:** în ediția HTML, quiz-urile se salvează în memoria locală a
> browserului. Dacă golești datele browserului sau folosești alt browser/dispozitiv, nu le
> mai vezi — de aceea e important să le **exporți** (secțiunea 8) ca backup.

---

## 7. Cele patru tipuri de întrebări

Alegi tipul din selectorul din antetul întrebării.

### 7.1. Grilă (4 variante)

Întrebarea clasică cu variante multiple.

1. Scrie textul întrebării.
2. Completează variantele de răspuns (**2 până la 4**).
3. Bifează **Corect** în dreptul variantei corecte (exact una).
4. Cu **✕** ștergi o variantă; cu **Adaugă variantă** adaugi (până la 4).

La joc, jucătorii apasă una dintre pastilele colorate cu forme (▲ ♦ ● ■).

### 7.2. Adevărat / Fals

O întrebare cu doar două variante fixe: **Adevărat** și **Fals**.

1. Scrie afirmația.
2. Bifează care variantă (Adevărat sau Fals) este cea corectă.

Etichetele „Adevărat"/„Fals" sunt fixe și nu se editează.

### 7.3. Răspuns liber

Jucătorul **scrie** răspunsul, nu alege dintr-o listă.

1. Scrie întrebarea.
2. La **Numărul corect... răspunsul afișat**, scrie răspunsul corect (prima variantă e cea
   arătată tuturor la revelare ca fiind „răspunsul corect").
3. Opțional, apeși **Adaugă variantă acceptată** ca să adaugi sinonime sau scrieri
   alternative (de ex. pentru „București" poți accepta și „Bucuresti"). Poți avea până la 6
   variante acceptate.

**Cum se punctează:** aplicația acceptă și **mici greșeli de tipar** (potrivire
aproximativă) și **ignoră diacriticele, majusculele și semnele de punctuație**. De exemplu,
„pariss" va fi acceptat pentru „Paris", iar „bucuresti" pentru „București". Cuvintele foarte
scurte (≤3 litere) se cer exact, ca să nu apară potriviri accidentale.

> Sfat: pune la variantele acceptate toate formele plauzibile pe care le-ar putea scrie
> cineva (abrevieri, forme cu/fără diacritice, sinonime).

### 7.4. Numeric („ghicește numărul")

Jucătorul scrie un **număr**, iar punctajul crește cu cât e mai aproape de răspuns.

1. Scrie întrebarea.
2. La **Numărul corect**, pune valoarea exactă (de ex. `42`).
3. La **Toleranță acceptată (±)**, pune cât de departe poate fi un răspuns și tot să fie
   considerat corect (de ex. `10` înseamnă că orice între 32 și 52 e corect).

**Cum se punctează:**

- Răspunsul **exact** ia punctajul maxim.
- Un răspuns **în interval** ia puncte proporțional cu apropierea: la marginea intervalului
  ia jumătate, iar cu cât e mai aproape de exact, cu atât mai mult.
- În afara intervalului = 0 puncte.
- **Toleranță 0** înseamnă că se acceptă doar răspunsul exact.
- Se acceptă **zecimale cu virgulă** (de ex. `3,5`) și numere negative.
- În jocul live, apropierea se combină cu **viteza** răspunsului.

---

## 8. Import și export

Formatul este JSON, identic în ambele ediții.

### Export

1. În bibliotecă, apeși **Exportă** pe quiz-ul dorit.
2. Se descarcă un fișier `.json` cu numele quiz-ului.
3. Păstrează-l ca backup sau trimite-l cuiva.

### Import

1. Pe ecranul principal, apeși **Importă**.
2. **Lipești** conținutul JSON în caseta de text (sau, după caz, încarci fișierul).
3. Confirmi. Quiz-ul apare în biblioteca ta.

Aplicația verifică fișierul la import: întrebările invalide sunt ignorate, iar un quiz nu
poate depăși **100 de întrebări** (surplusul se ignoră). Textul potențial periculos este
neutralizat automat, deci poți importa în siguranță fișiere primite de la alții.

> Fiindcă ambele ediții folosesc același format, un quiz exportat din HTML se importă în
> `index.php` și invers.

---

## 9. Modurile de joc

După ce apeși **Joacă** pe un quiz, alegi modul:

| Mod | Unde | Descriere |
|-----|------|-----------|
| **Solo** | ambele ediții | Joci singur, contra cronometru. |
| **Hotseat** | ambele ediții | Mai mulți jucători pe **același** dispozitiv, pe rând. |
| **Live** | doar server | Mai mulți jucători pe **dispozitivele lor**, sincron (Kahoot). |
| **Temă autonomă** | doar server | Fiecare rezolvă în ritmul lui, cu cod. |

La configurare mai ai:

- **🔀 Amestecă întrebările și variantele** — ordinea diferă la fiecare joc (vezi 15).
- **👥 Mod pe echipe** (doar la Live) — grupezi jucătorii în 2–4 echipe (vezi 14).
- **Lista de jucători** (la Solo/Hotseat) — numele participanților.

---

## 10. Joc Solo

1. Alege un quiz și apasă **Joacă**.
2. Alege modul **Solo**.
3. Opțional, scrie-ți numele (altfel apari ca „Tu").
4. Apasă **🚀 Începe jocul**.
5. Vezi o **numărătoare inversă**, apoi prima întrebare.
6. Răspunde înainte să expire timpul:
   - la Grilă / Adevărat-Fals: apeși varianta (sau tastele **1–4** / **Q W E R**);
   - la Răspuns liber / Numeric: **scrii** răspunsul și apeși **Trimite** (sau **Enter**).
7. După fiecare întrebare vezi **revelarea**: dacă ai răspuns corect, câte puncte ai luat,
   seria de răspunsuri corecte (streak) și scorul curent.
8. La final vezi **podiumul** cu scorul tău și o mică animație de confetti.

Punctajul depinde de **corectitudine** și de **viteză** (răspunsurile rapide iau mai multe
puncte), plus un **bonus de serie** pentru răspunsuri corecte consecutive.

---

## 11. Joc Hotseat

Perfect pentru o masă de prieteni cu un singur laptop/telefon.

1. Alege un quiz și apasă **Joacă**, apoi modul **Hotseat**.
2. Adaugă numele jucătorilor cu **Adaugă jucător** (până la 8). Cu **✕** scoți un jucător.
3. Apasă **🚀 Începe jocul**.
4. Pentru fiecare întrebare, aplicația anunță **al cui e rândul** („Predă lui...").
   Jucătorul curent apasă **Sunt gata ▶** și răspunde.
5. Ceilalți **nu** văd dacă răspunsul e corect până la revelare (ca să nu se copieze).
6. După ce toți au răspuns, apare **revelarea** cu răspunsul corect și cât a luat fiecare.
7. Între întrebări apare un **clasament** care arată cine urcă și cine coboară.
8. La final, **podium**.

> Predă efectiv dispozitivul de la un jucător la altul când aplicația îți cere.

---

## 12. Joc live sincron — gazda

Acesta este modul „Kahoot": tu proiectezi întrebările, iar sala răspunde de pe telefoane.
**Necesită ediția server** și **parola de gazdă**.

### Pasul 1 — Autentificare ca gazdă

1. Deschide adresa aplicației.
2. Autentifică-te ca gazdă cu **parola** primită de la administrator.
   - *La prima folosire a serverului*, dacă nu există încă parolă, ți se va cere să
     **stabilești** una (minimum 6 caractere). Vezi și *Manualul administratorului*.

### Pasul 2 — Crearea sesiunii de joc

1. Alege quiz-ul și pornește un **joc live**.
2. Opțional, activează **🔀 amestecarea** și/sau **👥 modul pe echipe** (2–4 echipe).
3. Confirmi crearea. Aplicația generează un **cod scurt** (de ex. `ABCD`).

### Pasul 3 — Invitarea participanților

Pe ecranul gazdei apar:

- **Codul** de intrare;
- un **cod QR** pe care participanții îl scanează;
- (după caz) linkul direct de intrare.

Participanții deschid adresa, introduc codul (sau scanează QR-ul) și intră în **lobby**.

### Pasul 4 — Lobby

- Vezi lista participanților care se alătură, în timp real.
- Dacă ai activat echipele, vezi câți membri are fiecare echipă.
- Aștepți să intre toți, apoi **pornești** jocul.

### Pasul 5 — Desfășurarea jocului

Pentru fiecare întrebare:

1. Gazda afișează întrebarea și pornește cronometrul.
2. Participanții răspund de pe dispozitivele lor.
3. La expirare (sau când răspund toți), gazda trece la **revelare**: răspunsul corect,
   distribuția răspunsurilor, cei mai rapizi.
4. Vezi **clasamentul** actualizat.
5. Treci la întrebarea următoare.

În timpul jocului, participanții pot trimite **reacții emoji** care plutesc pe ecranul
gazdei (vezi 15).

### Pasul 6 — Final

La ultima întrebare, gazda afișează **podiumul** (top 3) și, dacă ai jucat pe echipe,
**clasamentul pe echipe** cu echipa câștigătoare evidențiată. De aici poți deschide
**rapoartele** (secțiunea 19).

---

## 13. Joc live sincron — participantul

Ca participant **nu ai nevoie de cont sau parolă**.

1. Deschide adresa dată de gazdă (sau scanează codul QR).
2. Introdu **codul** jocului.
3. Alege-ți un **nume** (poți apăsa **🎲** pentru o **poreclă generată** automat) și un
   **avatar** (emoji).
4. Dacă jocul e pe echipe, **alege-ți echipa** (buton colorat).
5. Intri în **lobby** și aștepți gazda să pornească.
6. Pentru fiecare întrebare, **răspunde** pe telefonul tău înainte să expire timpul.
7. După fiecare întrebare vezi dacă ai punctat și locul tău.
8. Poți trimite **reacții emoji** din ecranul tău.
9. La final vezi **locul tău** (și al echipei tale, dacă e cazul).

---

## 14. Modul pe echipe

Disponibil la **jocul live**. Grupezi jucătorii în echipe colorate: **🔴 Roșii, 🔵
Albaștrii, 🟢 Verzii, 🟡 Galbenii**.

**Ca gazdă:**

1. La configurarea jocului live, activează **👥 Mod pe echipe**.
2. Alege numărul de echipe: **2, 3 sau 4**.
3. Creează jocul ca de obicei.

**Cum funcționează:**

- La intrare, fiecare participant **își alege echipa**.
- Fiecare joacă și punctează **individual**, dar scorurile se **însumează pe echipe**.
- În lobby, gazda vede numărul de membri per echipă.
- La final, pe lângă podiumul individual, apare un **clasament pe echipe** cu echipa
  câștigătoare evidențiată.
- Fiecare jucător își vede echipa și locul echipei.

---

## 15. Reacții, porecle, amestecare

### Reacții emoji (joc live)

În timpul jocului, participanții au o bară cu **👏 ❤️ 😮 😂 🔥 🎉**. Când apeși una, emoji-ul
**plutește pe ecranul gazdei**, creând atmosferă. Reacțiile sunt limitate ca ritm ca să nu
existe spam.

### Generator de porecle

La intrarea într-un joc live, butonul **🎲** de lângă câmpul de nume îți propune o
**poreclă aleatorie** prietenoasă (adjectiv + substantiv + număr), utilă dacă nu vrei să-ți
folosești numele real.

### Amestecare (shuffle)

La configurare, comutatorul **🔀 Amestecă întrebările și variantele** face ca:

- ordinea **întrebărilor** să fie diferită la fiecare joc;
- ordinea **variantelor** la întrebările grilă să fie amestecată.

Adevărat/Fals, răspunsul liber și numericul își păstrează structura. Funcționează în
toate modurile (Solo, Hotseat, Live).

> Amestecarea e utilă când reiei același quiz cu grupuri diferite sau când vrei să
> descurajezi copiatul între vecini.

---

## 16. Temă autonomă

Modul „temă" (self-paced, în stil Quizizz) permite fiecărui participant să rezolve quiz-ul
**în ritmul propriu**, nu sincron cu gazda. **Necesită ediția server.**

**Ca gazdă / profesor:**

1. Alege quiz-ul și creează o sesiune de tip **temă autonomă**.
2. Distribui **codul** participanților.

**Ca participant:**

1. Intri cu codul.
2. Parcurgi întrebările una câte una, în ritmul tău.
3. Primești feedback după fiecare întrebare.
4. La final vezi rezultatul tău.

Punctajul folosește aceleași reguli ca la joc (inclusiv apropierea la numeric), dar fără
presiunea vitezei sincrone.

---

## 17. Activități de audiență

Pe lângă quiz-uri, ediția server oferă **activități live de audiență** în stil
Slido/Mentimeter. Gazda creează o activitate, primește un cod/QR, iar publicul contribuie
de pe telefoane; rezultatele apar **în timp real** pe ecranul gazdei.

Tipuri disponibile:

### Nor de cuvinte (word cloud)

Publicul trimite cuvinte scurte; cuvintele mai frecvente apar mai mari. Bun pentru
„descrieți într-un cuvânt...".

### Sondaj (poll)

Gazda definește opțiuni; publicul votează; vezi procentele live pe bare colorate.

### Q&A (întrebări din public), cu moderare

Publicul trimite întrebări și le poate **vota** (▲) pe ale altora, ca cele populare să urce.
Gazda poate:

- **modera** — dacă moderarea e activă, întrebările trebuie **aprobate** înainte de a fi
  vizibile tuturor;
- marca o întrebare cu **stea** (★) sau ca **răspunsă** (✓);
- **ascunde** sau **șterge** întrebări.

### Rating / NPS

Publicul dă o notă (stele pe scală de 5, sau 0–10 pentru NPS). Vezi media / scorul NPS și
distribuția.

### Ranking

Publicul ordonează niște opțiuni după preferință; vezi clasamentul mediu.

### Scale (Likert)

Mai multe afirmații evaluate pe o scală (de ex. „dezacord ↔ acord"); vezi media fiecărei
afirmații.

### 100 de puncte

Fiecare participant distribuie un buget de 100 de puncte între opțiuni; vezi cum se împarte
„bugetul" audienței.

> **Deschis / Închis:** gazda poate **închide** o activitate ca să oprească noile
> contribuții, apoi o poate redeschide.

---

## 18. Prezentări (deck)

Ediția server permite construirea unei **prezentări** cu mai multe **slide-uri**, fiecare
fiind o activitate diferită (nor de cuvinte, sondaj, Q&A, rating, ranking, scale, 100 de
puncte).

1. Creezi o prezentare, îi dai un **titlu**.
2. Adaugi slide-uri, alegând tipul fiecăruia și configurându-l.
3. La rulare, gazda **navighează** între slide-uri cu **◀ / ▶**, iar publicul vede mereu
   activitatea curentă.

Util pentru un întreg atelier interactiv rulat dintr-o singură sesiune.

---

## 19. Rapoarte după joc

După un joc live, gazda poate deschide **rapoarte** cu:

- rezultate per participant (scor, răspunsuri corecte);
- statistici per întrebare (câți au răspuns, procent de corectitudine, timp mediu);
- pentru grilă/adevărat-fals, **distribuția** răspunsurilor pe variante.

Rapoartele pot fi **exportate** (CSV / JSON) sau **tipărite**.

> La întrebările de tip răspuns liber și numeric, raportul arată statisticile generale
> (câți, corectitudine), fără grafic de distribuție pe variante.

---

## 20. Roata norocului

Un instrument separat, **complet offline**, pentru alegeri aleatorii (cine răspunde, cine
câștigă, echipe la întâmplare etc.). Disponibil în **ambele ediții**, prin butonul **🎡
Roata** de pe ecranul principal.

1. Apeși **Roata**.
2. În panoul din dreapta, scrii **elementele**, câte unul pe linie (nume, opțiuni). Poți
   avea până la 24.
3. Apeși **🎯 Învârte**. Roata se rotește și se oprește pe un câștigător.
4. Opțiuni:
   - **Elimină câștigătorul după fiecare tragere** — util pentru a extrage pe rând, fără
     repetiție (se oprește la minimum 2 elemente);
   - **🔀 Amestecă** — reordonează elementele;
   - **↺ Resetează** — revine la lista implicită.

Lista se ține minte local între sesiuni.

---

## 21. Instalare ca aplicație (PWA)

Ediția server poate fi **instalată** ca aplicație pe telefon sau desktop (funcționează și
offline pentru ecranele deja vizitate).

- **Pe telefon (Chrome/Android):** deschide adresa, apoi meniul browserului → *Adaugă la
  ecranul principal*.
- **Pe desktop (Chrome/Edge):** apare o pictogramă de instalare în bara de adrese.
- **Pe iPhone (Safari):** butonul *Partajează* → *Adaugă la ecranul principal*.

> PWA necesită ca serverul să fie servit prin **HTTPS** (vezi *Manualul
> administratorului*).

---

## 22. Sfaturi și bune practici

- **Testează înainte.** Înainte de un atelier cu public, rulează un joc de probă cu 2–3
  dispozitive, ca să te asiguri că rețeaua, codul de intrare și fiecare tip de întrebare
  funcționează în condițiile tale reale.
- **Exportă-ți quiz-urile.** Mai ales în ediția offline, fă backup prin export JSON — nu te
  baza doar pe memoria browserului.
- **Timp potrivit.** Pune 20 de secunde pentru întrebări normale, 5–10 pentru cele scurte,
  60–120 pentru cele care cer gândire.
- **Răspuns liber cu generozitate.** Adaugă toate variantele acceptate plauzibile
  (sinonime, forme cu/fără diacritice, abrevieri).
- **Numeric cu toleranță realistă.** Alege o toleranță care recompensează apropierea, dar
  nu face orice răspuns corect.
- **Amestecarea** e prietena ta când reiei același quiz cu grupuri diferite.
- **Ecran mare pentru gazdă.** La jocul live, proiectează ecranul gazdei; participanții văd
  doar butoanele pe telefon.

---

## 23. Depanare

**Nu văd quiz-urile pe care le-am creat.**
În ediția offline, quiz-urile stau în memoria acelui browser. Verifică dacă folosești
același browser/dispozitiv și că nu ai golit datele. Pe viitor, exportă-le ca backup.

**Am importat un fișier și nu apare nimic / apar mai puține întrebări.**
Fișierul poate fi invalid sau depăși 100 de întrebări (surplusul se ignoră). Întrebările
fără text sau fără răspunsuri valide sunt sărite automat.

**La jocul live, participanții nu se pot conecta.**
Verifică: sunt pe aceeași rețea/au acces la adresă; au introdus corect codul; jocul nu a
fost deja închis. Cere administratorului să confirme că serverul e pornit și accesibil.

**Butonul de instalare (PWA) nu apare.**
PWA necesită HTTPS. Dacă serverul e pe HTTP simplu, instalarea nu e disponibilă (dar
aplicația funcționează normal în browser).

**Sunetul nu merge.**
Verifică comutatorul de sunet din bara de sus și volumul dispozitivului. Unele browsere cer
o primă interacțiune (un clic) înainte de a reda sunet.

**Câmpul de răspuns liber nu-mi ține textul.**
Scrie și apasă **Trimite** sau **Enter** înainte să expire timpul. Dacă timpul se termină,
întrebarea se consideră ratată.

---

## 24. Confidențialitate

- Aplicația **nu are telemetrie** și **nu urmărește** utilizatorii.
- În ediția offline, **toate datele rămân pe dispozitivul tău** (memoria browserului și
  fișierele exportate).
- În ediția server, sesiunile de joc și eventualele quiz-uri se păstrează pe serverul
  administratorului (vezi *Manualul administratorului* pentru detalii despre stocare și
  ștergere).
- Participanții la un joc live **nu au nevoie de cont**; folosesc doar un nume ales de ei
  (care poate fi și o poreclă generată).

---

*Undava — un instrument single-file, offline-first, trade-free.
Pentru instalare, configurare și întreținere, vezi **Manualul administratorului**.*

</script>
<script type="text/markdown" id="manual-admin-en">
# Undava — Administrator manual

*From installation to maintenance. For the server edition (`index.php`).*

---

## Contents

1. [Architecture in brief](#1-architecture)
2. [System requirements](#2-system-requirements)
3. [The offline edition (HTML) — "installation"](#3-offline-edition)
4. [Installing the server edition — overview](#4-installing-server)
5. [Installation with Apache](#5-apache)
6. [Installation with Nginx + PHP-FPM](#6-nginx)
7. [Quick installation for testing (`php -S`)](#7-quick-test)
8. [First configuration: the host password](#8-host-password)
9. [File and data structure](#9-file-structure)
10. [Security — mandatory reading](#10-security)
11. [HTTPS and PWA](#11-https-pwa)
12. [Maintenance](#12-maintenance)
13. [Backup and restore](#13-backup-restore)
14. [Updating the application](#14-updating)
15. [Resetting the host password](#15-reset-password)
16. [Troubleshooting](#16-troubleshooting)
17. [Performance and limits](#17-performance-limits)
18. [Checklist before a workshop](#18-checklist)
19. [An honest note about the state of testing](#19-testing-note)

---

## 1. Architecture in brief

- **A single file**: `index.php`. It contains the server (PHP), the API and the interface (HTML/JS) together.
- **No database.** Storage on **flat files** in a `data/` directory created automatically next to `index.php`.
- **No external dependencies.** It doesn't need Composer, npm, libraries or external services. Just PHP.
- **State**: game/activity sessions are JSON files in `data/live/`. The configuration (the host password hash) is in `data/.config.php`.
- **Authentication**: a single privileged role — the **host/administrator** — protected with a password (`password_hash` hash) and a per-session **CSRF** token. Game participants don't need an account.

---

## 2. System requirements

- **PHP 8.0 or newer** (tested on **PHP 8.3.6**). 8.1+ recommended.
  - Required extensions: the standard ones (`json`, `session`, preferably `mbstring`). No exotic extensions are needed. `intl`/`Normalizer` are **not** mandatory — the app has a fallback for Romanian diacritics.
- A **web server** that runs PHP: Apache (mod_php or PHP-FPM), Nginx + PHP-FPM, or the built-in `php -S` server (for testing only).
- **Disk space**: the app itself is ~350 KB. The data grows with the number of sessions (each game = a JSON file of the order of tens–hundreds of KB).
- **Permissions**: the directory where you put `index.php` must be **writable by the web server user** (so it can create `data/`).
- **HTTPS**: strongly recommended (mandatory for PWA and for protecting the password/session cookie). See section 11.

---

## 3. The offline edition (HTML)

`quiz-fara-frontiere.html` requires no installation:

- You can simply distribute it (email, USB stick, file sharing) and anyone opens it with a double-click.
- Optionally, you can also serve it from a web server as a static file, but it isn't necessary.
- It doesn't create files on the server; the data stays in each user's browser.

The rest of this manual concerns the **server edition** (`index.php`).

---

## 4. Installing the server edition

General steps (detailed per server below):

1. Copy `index.php` into the desired directory in the web root (the site root or a subdirectory, e.g. `/var/www/quiz/`).
2. Make sure the **web server user** (`www-data` on Debian/Ubuntu) can **write** in that directory, so the app can create `data/` itself.
3. Configure the web server to run PHP and to **block web access to `data/`**.
4. Open the address in a browser and **set the host password** (section 8).

> The `data/` directory (with the subdirectories `data/live/` and `data/quizzes/`, plus the protection files `.htaccess` and `index.html`) is created **automatically** on first access, if permissions allow.

---

## 5. Installation with Apache

We assume Ubuntu/Debian with Apache and PHP.

### 5.1. Copy the file and set permissions

```bash
sudo mkdir -p /var/www/quiz
sudo cp index.php /var/www/quiz/
sudo chown -R www-data:www-data /var/www/quiz
sudo chmod 755 /var/www/quiz
```

### 5.2. Ensure PHP execution

- With **mod_php**: check `sudo a2enmod php8.3` (or your version) and restart Apache.
- With **PHP-FPM**: `sudo a2enmod proxy_fcgi setenvif && sudo a2enconf php8.3-fpm`.

### 5.3. Allow `.htaccess` (for protecting `data/`)

The app automatically writes `data/.htaccess` with `Require all denied`. For this to take effect, your vhost must allow overrides in that directory. In the site configuration (e.g. `/etc/apache2/sites-available/000-default.conf`):

```apache
<Directory /var/www/quiz>
    AllowOverride All
    Require all granted
</Directory>
```

Or, more safely, **explicitly block** `data/` directly in the vhost (independent of `.htaccess`):

```apache
<Directory /var/www/quiz/data>
    Require all denied
</Directory>
```

Restart: `sudo systemctl reload apache2`.

### 5.4. Test

Open `http://your-server/quiz/`. You should see the home screen. Then check that `http://your-server/quiz/data/.config.php` is **not** accessible (it must return 403 or empty content).

---

## 6. Installation with Nginx + PHP-FPM

Nginx does **not** read `.htaccess`, so the protection of the `data/` directory must be configured manually — **this step is mandatory**.

### 6.1. Copy the file and set permissions

```bash
sudo mkdir -p /var/www/quiz
sudo cp index.php /var/www/quiz/
sudo chown -R www-data:www-data /var/www/quiz
sudo chmod 755 /var/www/quiz
```

### 6.2. Configure the site

Example server block (adjust the PHP-FPM socket path to your version):

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/quiz;
    index index.php;

    # BLOCK web access to the data directory (MANDATORY)
    location ^~ /data/ {
        deny all;
        return 403;
    }

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

### 6.3. Test and restart

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Open `http://example.com/` and then check that `http://example.com/data/.config.php` returns **403**.

---

## 7. Quick installation for testing

For local trials (not for production):

```bash
cd /path/to/the-folder-with-index.php
php -S 0.0.0.0:8080
```

Then open `http://localhost:8080/`. The built-in server runs PHP and creates `data/` in the current folder.

> ⚠️ The `php -S` server is **for development only**. Don't expose it on the internet and don't use it for a real workshop — it doesn't have the protections and robustness of a real web server.

---

## 8. First configuration: the host password

On first use there is no password set. The first person who wants to **create** games must set the host password:

1. Open the app's address.
2. Enter the host / login area.
3. Since there is no password yet, you are asked to **set** one. Enter a password of **at least 6 characters** (a much longer and unique one is recommended).
4. The password is **hashed** (`password_hash`, PHP's default algorithm) and saved in `data/.config.php`. The password text is **not** stored anywhere.
5. After setting it, you are automatically authenticated as host.

**Subsequent logins:** you enter the password; the app verifies it with `password_verify`. On success, your session receives host rights and a CSRF token. There is a small delay on a wrong password, to discourage brute-force guessing.

**Logout:** the logout button clears the host rights from the session.

> The password protects the **creation and control** of games/activities. Participants don't need it. Choose a password you can safely share with co-organizers, if applicable.

---

## 9. File and data structure

Everything sits next to `index.php`, in the `data/` directory (auto-created):

```
index.php                  ← the app (single file)
data/                      ← data directory (auto-created, NON-PUBLIC)
├── .config.php            ← configuration: host password hash, moderation flag (SENSITIVE)
├── .htaccess              ← blocks web access (Apache only)
├── index.html             ← 403 placeholder, anti-listing
├── feedback.txt           ← messages from the "guestbook" (one line/record)
├── quizzes/               ← quizzes stored on the server (if applicable)
└── live/                  ← live sessions: one JSON file per game/activity
    ├── ABCD.json
    ├── EFGH.json
    └── ...
```

Important points:

- **`data/.config.php`** contains the password hash — treat it as a secret. Being a `.php` file, even if accessed via the web, it returns empty content (it executes, it isn't displayed). Nonetheless, protect the `data/` directory (see security).
- **`data/live/*.json`** are the game sessions. They **accumulate** — each live game or activity creates a file. They must be **cleaned periodically** (section 12).
- **Writes are atomic** (temporary file + rename) and **serialized** (locking with `flock`), so they don't get corrupted under simultaneous access.
- Session codes are short (4 characters from `A–Z0–9`, without ambiguous characters) and strictly validated — they can't contain file paths.

---

## 10. Security

The app is designed defensively, but **a few measures depend on you, the administrator.**

### What the app does on its own

- **Consistent escaping** of user data on display (XSS protection).
- **Strict sanitization** of codes/IDs used in file paths (no path traversal).
- **CSRF**: host actions (create/control) require a CSRF token verified with `hash_equals`.
- **Session ID regeneration** on authentication (anti session-fixation).
- **Request-size limiting** (rejects payloads that are too large) and **capping** of inputs (number of questions, text lengths, rating values, etc.).
- **Rate-limiting** on submissions and on reactions.

### What YOU must do

1. **Block web access to `data/`** — mandatory on Nginx (section 6), verified on Apache (section 5). Manually confirm that `.../data/.config.php` returns 403.
2. **Enable HTTPS** (section 11). Without it, the host password and the session cookie travel in the clear.
3. **Harden the session cookie.** The app uses PHP's default settings. Recommended, in `php.ini` (or in the PHP-FPM pool configuration):

   ```ini
   session.cookie_httponly = 1
   session.cookie_secure   = 1   ; requires HTTPS
   session.cookie_samesite = Lax
   session.use_strict_mode = 1
   ```

4. **Add security headers** at the web server level (the app doesn't set them itself). Minimal example (Nginx):

   ```nginx
   add_header X-Content-Type-Options "nosniff" always;
   add_header X-Frame-Options "SAMEORIGIN" always;
   add_header Referrer-Policy "strict-origin-when-cross-origin" always;
   # A suitable CSP policy requires testing, because the interface uses inline styles/scripts.
   ```

   > Note: the interface uses **inline** JS and CSS, so a strict CSP with a restrictive `script-src` will block it. If you want CSP, test carefully (possibly `'unsafe-inline'` to start, then refine).

5. **Restrict access if needed.** If the server is only for an internal workshop, you can limit access to a network/VPN or add server-level authentication.

### Known limitation: the language filter

The optional profanity filter (on public activities) is **basic** and easy to bypass ("l33t" writing, spacing, homoglyphs). Treat it as a cosmetic aid, **not** as reliable moderation. For real control of public content, use **Q&A moderation** (approval before display) and human oversight.

---

## 11. HTTPS and PWA

- **HTTPS** is required for: installation as an app (PWA), `session.cookie_secure`, and protecting the host password.
- The simplest way on Ubuntu: **Let's Encrypt** with Certbot.

  ```bash
  sudo apt install certbot python3-certbot-nginx   # or -apache
  sudo certbot --nginx -d example.com              # or --apache
  ```

- **PWA**: the app serves the manifest and the service worker itself (via internal parameters of the `?asset=...` type). Once on HTTPS, users can install the app from the browser. No additional configuration is needed.

---

## 12. Maintenance

### Cleaning old sessions (recommended)

The files in `data/live/` accumulate. Delete the old ones periodically. Example cron task that deletes sessions unmodified for over **24 hours**:

```bash
# edit the crontab of the web server user
sudo -u www-data crontab -e
```

Add a line (adjust the path):

```cron
0 3 * * * find /var/www/quiz/data/live -name '*.json' -mmin +1440 -delete
```

This runs daily at 03:00 and deletes sessions older than 1440 minutes (24 h). Adjust the threshold to your needs (e.g. `-mmin +720` for 12 h).

> Don't delete `data/.config.php` (the password) and `data/feedback.txt` unless intentionally.

### Monitoring disk space

```bash
du -sh /var/www/quiz/data
ls -1 /var/www/quiz/data/live | wc -l   # how many active/residual sessions
```

### Checking permissions

If the app can't write (games aren't created), check:

```bash
ls -ld /var/www/quiz /var/www/quiz/data
sudo chown -R www-data:www-data /var/www/quiz/data
```

---

## 13. Backup and restore

What matters for backup: **`data/.config.php`** (the password) and, if you use the server storage of quizzes, **`data/quizzes/`**. Live sessions (`data/live/`) are usually ephemeral and don't need a backup.

### Backup

```bash
sudo tar czf quiz-backup-$(date +%F).tar.gz -C /var/www/quiz data
```

Keep the archive in a safe place (it contains the password hash).

### Restore

```bash
sudo tar xzf quiz-backup-2026-07-01.tar.gz -C /var/www/quiz
sudo chown -R www-data:www-data /var/www/quiz/data
```

---

## 14. Updating the application

Being a single file, updating is simple:

1. **Back up** `data/` (section 13) and keep a copy of the old `index.php`.
2. Replace `index.php` with the new version.
3. Fix the owner if needed: `sudo chown www-data:www-data /var/www/quiz/index.php`.
4. Reload the page and **test** a trial game.

The data in `data/` (including the password) **is kept** across the update, because it is separate from `index.php`. The quiz format is stable between versions, so existing quizzes remain compatible.

---

## 15. Resetting the host password

If you forgot the password:

1. Back up `data/`.
2. **Delete** (or rename) the configuration file:

   ```bash
   sudo mv /var/www/quiz/data/.config.php /var/www/quiz/data/.config.php.bak
   ```

3. Reload the app. Since there is no password set anymore, you'll be able to **set a new one** just like at first configuration (section 8).

> This only resets the password; it does **not** affect quizzes or sessions. The old `.config.php.bak` file contains only the old hash — delete it after you've made sure everything is fine.

---

## 16. Troubleshooting

**The page is blank / error 500.**
Check the PHP/server error log:
- Apache: `sudo tail -f /var/log/apache2/error.log`
- Nginx + PHP-FPM: `sudo tail -f /var/log/nginx/error.log` and `/var/log/php8.3-fpm.log`
Frequent causes: PHP version too old (<8.0), wrong directory permissions.

**Games/activities aren't created ("write failed").**
The `data/` (or `data/live/`) directory isn't writable. Fix the owner/permissions:
```bash
sudo chown -R www-data:www-data /var/www/quiz/data
sudo chmod -R u+rwX /var/www/quiz/data
```

**The files in `data/` are accessible from the browser.**
You didn't block the directory. On Nginx add the block `location ^~ /data/ { deny all; }` (section 6); on Apache check `AllowOverride All` or block in the vhost (section 5). Confirm with a request to `.../data/.config.php` (it must be 403).

**"bad csrf" on host actions.**
The session expired or cookies are blocked. Re-authenticate as host. Also check that session cookies work (correct domain/HTTPS).

**Installation as an app (PWA) doesn't appear.**
PWA requires HTTPS. Configure a certificate (section 11).

**Diacritics/matching on free answer seem odd.**
The app has an internal fallback for Romanian diacritics even without the `intl` extension. If you still see problems, installing the `php-intl` and `php-mbstring` extensions can help:
```bash
sudo apt install php8.3-intl php8.3-mbstring && sudo systemctl reload php8.3-fpm
```

**PHP sessions don't persist (it logs you out often).**
Check the `session.save_path` settings in `php.ini` and that that directory is writable; check the server clock and the cookie configuration.

---

## 17. Performance and limits

- **Without a database**, each answer in a live game involves a read-modify-write with locking of the session file. For typical workshop sizes (tens of participants) it is perfectly adequate.
- **For very large groups** (many hundreds of participants simultaneously on the same session), contention on the session file may increase latency. The app is designed for workshops, not for stadium-type mass events.
- **Default limits** (for robustness reasons): a maximum of **100 questions** per quiz, maximum lengths for title/questions/answers/names/messages, maximum request size. These limits protect the server from abuse and from accidental huge inputs.
- **Clean `data/live/`** periodically (section 12) to avoid the accumulation of files.

---

## 18. Checklist before a workshop

- [ ] `index.php` is on the server and the home page loads.
- [ ] Web access to `data/` is **blocked** (verified with a request to `.../data/.config.php`).
- [ ] **HTTPS** active (required for PWA and security).
- [ ] The host password is **set** and you know it.
- [ ] You've done a **full end-to-end trial live game** with 2–3 real devices, on the network you will use, testing **each question type** (multiple choice, true-false, free answer, numeric) and, if you use them, **teams** and **audience activities**.
- [ ] You've tested the **join code** and the **QR** from a phone in the room.
- [ ] You have a backup plan (e.g. the offline HTML edition) in case of network problems.
- [ ] The session **cleaning** task is configured (cron) or you know how to do it manually.
- [ ] You have a recent **backup** of `data/`.

---

## 19. An honest note about testing

This app was developed and intensively verified at the **logic** level (scoring formulas, text matching, aggregation, validations), but the **integrated end-to-end flow on a real server, in the browser, with several devices** is your responsibility to validate before relying on it publicly. In particular:

- **Run a complete live game** on your real infrastructure before a workshop — this is where any integration problems that don't appear in component testing come to light.
- **Test the combinations** you'll actually use (e.g. presentation with moderated Q&A + filter, teams + numeric questions, offline re-entry in the PWA).
- **Check the session headers and cookies** if you expose the app on the internet, not just on a local workshop network.
- Remember that the **language filter is weak** — rely on Q&A moderation and human oversight for public content.

Treat it as a solid tool, but **test it end-to-end yourself** under your conditions before putting it in front of an audience.

---

*Undava — the server edition. For the actual use (creating quizzes, playing, activities), see the **User manual**.*
</script>
<script type="text/markdown" id="manual-admin-fr">
# Undava — Manuel de l'administrateur

*De l'installation à la maintenance. Pour l'édition serveur (`index.php`).*

---

## Sommaire

1. [Architecture en bref](#1-architecture)
2. [Configuration système requise](#2-configuration-requise)
3. [L'édition hors ligne (HTML) — « installation »](#3-edition-hors-ligne)
4. [Installer l'édition serveur — vue d'ensemble](#4-installer-serveur)
5. [Installation avec Apache](#5-apache)
6. [Installation avec Nginx + PHP-FPM](#6-nginx)
7. [Installation rapide pour test (`php -S`)](#7-test-rapide)
8. [Première configuration : le mot de passe d'hôte](#8-mot-de-passe-hote)
9. [Structure des fichiers et des données](#9-structure-fichiers)
10. [Sécurité — lecture obligatoire](#10-securite)
11. [HTTPS et PWA](#11-https-pwa)
12. [Maintenance](#12-maintenance)
13. [Sauvegarde et restauration](#13-sauvegarde-restauration)
14. [Mise à jour de l'application](#14-mise-a-jour)
15. [Réinitialiser le mot de passe d'hôte](#15-reinitialiser-mot-de-passe)
16. [Dépannage](#16-depannage)
17. [Performance et limites](#17-performance-limites)
18. [Liste de contrôle avant un atelier](#18-liste-controle)
19. [Une note honnête sur l'état des tests](#19-note-tests)

---

## 1. Architecture en bref

- **Un seul fichier** : `index.php`. Il contient le serveur (PHP), l'API et l'interface (HTML/JS) réunis.
- **Sans base de données.** Stockage sur **fichiers plats** dans un répertoire `data/` créé automatiquement à côté de `index.php`.
- **Sans dépendances externes.** Il n'a pas besoin de Composer, npm, de bibliothèques ou de services externes. Juste PHP.
- **État** : les sessions de jeu/d'activités sont des fichiers JSON dans `data/live/`. La configuration (le hachage du mot de passe d'hôte) est dans `data/.config.php`.
- **Authentification** : un seul rôle privilégié — **l'hôte/administrateur** — protégé par un mot de passe (hachage `password_hash`) et un jeton **CSRF** par session. Les participants aux jeux n'ont pas besoin de compte.

---

## 2. Configuration système requise

- **PHP 8.0 ou plus récent** (testé sur **PHP 8.3.6**). 8.1+ recommandé.
  - Extensions requises : les standard (`json`, `session`, de préférence `mbstring`). Aucune extension exotique n'est nécessaire. `intl`/`Normalizer` ne sont **pas** obligatoires — l'application dispose d'une solution de repli pour les diacritiques roumains.
- Un **serveur web** qui exécute PHP : Apache (mod_php ou PHP-FPM), Nginx + PHP-FPM, ou le serveur intégré `php -S` (pour test uniquement).
- **Espace disque** : l'application elle-même fait ~350 Ko. Les données croissent avec le nombre de sessions (chaque jeu = un fichier JSON de l'ordre de dizaines à centaines de Ko).
- **Permissions** : le répertoire où tu places `index.php` doit être **accessible en écriture par l'utilisateur du serveur web** (pour qu'il puisse créer `data/`).
- **HTTPS** : fortement recommandé (obligatoire pour le PWA et pour protéger le mot de passe/le cookie de session). Voir la section 11.

---

## 3. L'édition hors ligne (HTML)

`quiz-fara-frontiere.html` ne nécessite aucune installation :

- Tu peux simplement le distribuer (e-mail, clé USB, partage de fichiers) et n'importe qui l'ouvre d'un double-clic.
- Éventuellement, tu peux aussi le servir depuis un serveur web comme fichier statique, mais ce n'est pas nécessaire.
- Il ne crée pas de fichiers sur le serveur ; les données restent dans le navigateur de chaque utilisateur.

Le reste de ce manuel concerne l'**édition serveur** (`index.php`).

---

## 4. Installer l'édition serveur

Étapes générales (détaillées par serveur ci-dessous) :

1. Copie `index.php` dans le répertoire souhaité de la racine web (la racine du site ou un sous-répertoire, par ex. `/var/www/quiz/`).
2. Assure-toi que l'**utilisateur du serveur web** (`www-data` sur Debian/Ubuntu) peut **écrire** dans ce répertoire, pour que l'application puisse créer `data/` elle-même.
3. Configure le serveur web pour exécuter PHP et **bloquer l'accès web à `data/`**.
4. Ouvre l'adresse dans un navigateur et **définis le mot de passe d'hôte** (section 8).

> Le répertoire `data/` (avec les sous-répertoires `data/live/` et `data/quizzes/`, plus les fichiers de protection `.htaccess` et `index.html`) est créé **automatiquement** au premier accès, si les permissions le permettent.

---

## 5. Installation avec Apache

Nous supposons Ubuntu/Debian avec Apache et PHP.

### 5.1. Copie le fichier et règle les permissions

```bash
sudo mkdir -p /var/www/quiz
sudo cp index.php /var/www/quiz/
sudo chown -R www-data:www-data /var/www/quiz
sudo chmod 755 /var/www/quiz
```

### 5.2. Assure l'exécution de PHP

- Avec **mod_php** : vérifie `sudo a2enmod php8.3` (ou ta version) et redémarre Apache.
- Avec **PHP-FPM** : `sudo a2enmod proxy_fcgi setenvif && sudo a2enconf php8.3-fpm`.

### 5.3. Autorise `.htaccess` (pour la protection de `data/`)

L'application écrit automatiquement `data/.htaccess` avec `Require all denied`. Pour que cela prenne effet, ton vhost doit autoriser les surcharges dans ce répertoire. Dans la configuration du site (par ex. `/etc/apache2/sites-available/000-default.conf`) :

```apache
<Directory /var/www/quiz>
    AllowOverride All
    Require all granted
</Directory>
```

Ou, plus sûrement, **bloque explicitement** `data/` directement dans le vhost (indépendamment de `.htaccess`) :

```apache
<Directory /var/www/quiz/data>
    Require all denied
</Directory>
```

Redémarre : `sudo systemctl reload apache2`.

### 5.4. Teste

Ouvre `http://ton-serveur/quiz/`. Tu devrais voir l'écran d'accueil. Vérifie ensuite que `http://ton-serveur/quiz/data/.config.php` n'est **pas** accessible (il doit renvoyer 403 ou un contenu vide).

---

## 6. Installation avec Nginx + PHP-FPM

Nginx ne lit **pas** `.htaccess`, donc la protection du répertoire `data/` doit être configurée manuellement — **cette étape est obligatoire**.

### 6.1. Copie le fichier et règle les permissions

```bash
sudo mkdir -p /var/www/quiz
sudo cp index.php /var/www/quiz/
sudo chown -R www-data:www-data /var/www/quiz
sudo chmod 755 /var/www/quiz
```

### 6.2. Configure le site

Exemple de bloc serveur (ajuste le chemin du socket PHP-FPM à ta version) :

```nginx
server {
    listen 80;
    server_name exemple.com;
    root /var/www/quiz;
    index index.php;

    # BLOQUE l'accès web au répertoire de données (OBLIGATOIRE)
    location ^~ /data/ {
        deny all;
        return 403;
    }

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

### 6.3. Teste et redémarre

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Ouvre `http://exemple.com/` puis vérifie que `http://exemple.com/data/.config.php` renvoie **403**.

---

## 7. Installation rapide pour test

Pour des essais locaux (pas pour la production) :

```bash
cd /chemin/vers/le-dossier-avec-index.php
php -S 0.0.0.0:8080
```

Puis ouvre `http://localhost:8080/`. Le serveur intégré exécute PHP et crée `data/` dans le dossier courant.

> ⚠️ Le serveur `php -S` est **uniquement pour le développement**. Ne l'expose pas sur internet et ne l'utilise pas pour un vrai atelier — il n'a pas les protections et la robustesse d'un vrai serveur web.

---

## 8. Première configuration : le mot de passe d'hôte

À la première utilisation, aucun mot de passe n'est défini. La première personne qui veut **créer** des jeux doit définir le mot de passe d'hôte :

1. Ouvre l'adresse de l'application.
2. Entre dans la zone d'hôte / d'authentification.
3. Comme il n'y a pas encore de mot de passe, on te demande d'en **définir** un. Saisis un mot de passe d'**au moins 6 caractères** (un mot bien plus long et unique est recommandé).
4. Le mot de passe est **haché** (`password_hash`, l'algorithme par défaut de PHP) et enregistré dans `data/.config.php`. Le texte du mot de passe n'est stocké **nulle part**.
5. Après l'avoir défini, tu es automatiquement authentifié comme hôte.

**Authentifications ultérieures :** tu saisis le mot de passe ; l'application le vérifie avec `password_verify`. En cas de succès, ta session reçoit les droits d'hôte et un jeton CSRF. Il y a un petit délai en cas de mauvais mot de passe, pour décourager la devinette par force brute.

**Déconnexion :** le bouton de déconnexion efface les droits d'hôte de la session.

> Le mot de passe protège la **création et le contrôle** des jeux/activités. Les participants n'en ont pas besoin. Choisis un mot de passe que tu peux partager en toute sécurité avec les co-organisateurs, le cas échéant.

---

## 9. Structure des fichiers et des données

Tout se trouve à côté de `index.php`, dans le répertoire `data/` (auto-créé) :

```
index.php                  ← l'application (fichier unique)
data/                      ← répertoire de données (auto-créé, NON-PUBLIC)
├── .config.php            ← configuration : hachage mot de passe hôte, indicateur modération (SENSIBLE)
├── .htaccess              ← bloque l'accès web (Apache uniquement)
├── index.html             ← espace réservé 403, anti-listage
├── feedback.txt           ← messages du « livre d'or » (une ligne/enregistrement)
├── quizzes/               ← questionnaires stockés sur le serveur (le cas échéant)
└── live/                  ← sessions en direct : un fichier JSON par jeu/activité
    ├── ABCD.json
    ├── EFGH.json
    └── ...
```

Points importants :

- **`data/.config.php`** contient le hachage du mot de passe — traite-le comme un secret. Étant un fichier `.php`, même s'il est accédé via le web, il renvoie un contenu vide (il s'exécute, il ne s'affiche pas). Néanmoins, protège le répertoire `data/` (voir sécurité).
- **`data/live/*.json`** sont les sessions de jeu. Elles **s'accumulent** — chaque jeu en direct ou activité crée un fichier. Elles doivent être **nettoyées périodiquement** (section 12).
- **Les écritures sont atomiques** (fichier temporaire + renommage) et **sérialisées** (verrouillage avec `flock`), donc elles ne se corrompent pas lors d'accès simultanés.
- Les codes de session sont courts (4 caractères de `A–Z0–9`, sans caractères ambigus) et strictement validés — ils ne peuvent pas contenir de chemins de fichiers.

---

## 10. Sécurité

L'application est conçue de manière défensive, mais **quelques mesures dépendent de toi, l'administrateur.**

### Ce que l'application fait d'elle-même

- **Échappement cohérent** des données des utilisateurs à l'affichage (protection XSS).
- **Assainissement strict** des codes/ID utilisés dans les chemins de fichiers (pas de traversée de répertoire).
- **CSRF** : les actions d'hôte (création/contrôle) requièrent un jeton CSRF vérifié avec `hash_equals`.
- **Régénération de l'ID de session** à l'authentification (anti-fixation de session).
- **Limitation de la taille des requêtes** (rejette les charges utiles trop grandes) et **plafonnement** des entrées (nombre de questions, longueurs de texte, valeurs de notation, etc.).
- **Limitation de débit** sur les envois et sur les réactions.

### Ce que TU dois faire

1. **Bloque l'accès web à `data/`** — obligatoire sur Nginx (section 6), vérifié sur Apache (section 5). Confirme manuellement que `.../data/.config.php` renvoie 403.
2. **Active HTTPS** (section 11). Sans lui, le mot de passe d'hôte et le cookie de session circulent en clair.
3. **Renforce le cookie de session.** L'application utilise les réglages par défaut de PHP. Recommandé, dans `php.ini` (ou dans la configuration du pool PHP-FPM) :

   ```ini
   session.cookie_httponly = 1
   session.cookie_secure   = 1   ; nécessite HTTPS
   session.cookie_samesite = Lax
   session.use_strict_mode = 1
   ```

4. **Ajoute des en-têtes de sécurité** au niveau du serveur web (l'application ne les définit pas elle-même). Exemple minimal (Nginx) :

   ```nginx
   add_header X-Content-Type-Options "nosniff" always;
   add_header X-Frame-Options "SAMEORIGIN" always;
   add_header Referrer-Policy "strict-origin-when-cross-origin" always;
   # Une politique CSP adaptée nécessite des tests, car l'interface utilise des styles/scripts en ligne.
   ```

   > Note : l'interface utilise du JS et du CSS **en ligne**, donc une CSP stricte avec un `script-src` restrictif la bloquera. Si tu veux une CSP, teste avec soin (possiblement `'unsafe-inline'` pour commencer, puis affine).

5. **Restreins l'accès si nécessaire.** Si le serveur n'est que pour un atelier interne, tu peux limiter l'accès à un réseau/VPN ou ajouter une authentification au niveau du serveur.

### Limitation connue : le filtre de langage

Le filtre optionnel de langage grossier (sur les activités publiques) est **basique** et facile à contourner (écriture « l33t », espacement, homoglyphes). Traite-le comme une aide cosmétique, **pas** comme une modération fiable. Pour un contrôle réel du contenu public, utilise la **modération des Q&R** (approbation avant affichage) et une surveillance humaine.

---

## 11. HTTPS et PWA

- **HTTPS** est nécessaire pour : l'installation comme application (PWA), `session.cookie_secure`, et la protection du mot de passe d'hôte.
- La voie la plus simple sur Ubuntu : **Let's Encrypt** avec Certbot.

  ```bash
  sudo apt install certbot python3-certbot-nginx   # ou -apache
  sudo certbot --nginx -d exemple.com              # ou --apache
  ```

- **PWA** : l'application sert elle-même le manifeste et le service worker (via des paramètres internes du type `?asset=...`). Une fois en HTTPS, les utilisateurs peuvent installer l'application depuis le navigateur. Aucune configuration supplémentaire n'est nécessaire.

---

## 12. Maintenance

### Nettoyage des anciennes sessions (recommandé)

Les fichiers dans `data/live/` s'accumulent. Supprime périodiquement les anciens. Exemple de tâche cron qui supprime les sessions non modifiées depuis plus de **24 heures** :

```bash
# édite la crontab de l'utilisateur du serveur web
sudo -u www-data crontab -e
```

Ajoute une ligne (ajuste le chemin) :

```cron
0 3 * * * find /var/www/quiz/data/live -name '*.json' -mmin +1440 -delete
```

Cela s'exécute quotidiennement à 03:00 et supprime les sessions plus anciennes que 1440 minutes (24 h). Ajuste le seuil selon tes besoins (par ex. `-mmin +720` pour 12 h).

> Ne supprime pas `data/.config.php` (le mot de passe) et `data/feedback.txt` sauf intentionnellement.

### Surveillance de l'espace disque

```bash
du -sh /var/www/quiz/data
ls -1 /var/www/quiz/data/live | wc -l   # combien de sessions actives/résiduelles
```

### Vérification des permissions

Si l'application ne peut pas écrire (les jeux ne se créent pas), vérifie :

```bash
ls -ld /var/www/quiz /var/www/quiz/data
sudo chown -R www-data:www-data /var/www/quiz/data
```

---

## 13. Sauvegarde et restauration

Ce qui compte pour la sauvegarde : **`data/.config.php`** (le mot de passe) et, si tu utilises le stockage serveur des questionnaires, **`data/quizzes/`**. Les sessions en direct (`data/live/`) sont généralement éphémères et ne nécessitent pas de sauvegarde.

### Sauvegarde

```bash
sudo tar czf quiz-backup-$(date +%F).tar.gz -C /var/www/quiz data
```

Garde l'archive dans un endroit sûr (elle contient le hachage du mot de passe).

### Restauration

```bash
sudo tar xzf quiz-backup-2026-07-01.tar.gz -C /var/www/quiz
sudo chown -R www-data:www-data /var/www/quiz/data
```

---

## 14. Mise à jour de l'application

Étant un seul fichier, la mise à jour est simple :

1. **Sauvegarde** `data/` (section 13) et garde une copie de l'ancien `index.php`.
2. Remplace `index.php` par la nouvelle version.
3. Répare le propriétaire si nécessaire : `sudo chown www-data:www-data /var/www/quiz/index.php`.
4. Recharge la page et **teste** un jeu d'essai.

Les données dans `data/` (y compris le mot de passe) **sont conservées** lors de la mise à jour, car elles sont séparées de `index.php`. Le format des questionnaires est stable entre les versions, donc les questionnaires existants restent compatibles.

---

## 15. Réinitialiser le mot de passe d'hôte

Si tu as oublié le mot de passe :

1. Sauvegarde `data/`.
2. **Supprime** (ou renomme) le fichier de configuration :

   ```bash
   sudo mv /var/www/quiz/data/.config.php /var/www/quiz/data/.config.php.bak
   ```

3. Recharge l'application. Comme il n'y a plus de mot de passe défini, tu pourras en **définir un nouveau** comme à la première configuration (section 8).

> Cela réinitialise seulement le mot de passe ; cela **n'affecte pas** les questionnaires ou les sessions. L'ancien fichier `.config.php.bak` ne contient que l'ancien hachage — supprime-le après t'être assuré que tout est en ordre.

---

## 16. Dépannage

**La page est blanche / erreur 500.**
Vérifie le journal d'erreurs PHP/du serveur :
- Apache : `sudo tail -f /var/log/apache2/error.log`
- Nginx + PHP-FPM : `sudo tail -f /var/log/nginx/error.log` et `/var/log/php8.3-fpm.log`
Causes fréquentes : version de PHP trop ancienne (<8.0), permissions incorrectes sur le répertoire.

**Les jeux/activités ne se créent pas (« write failed »).**
Le répertoire `data/` (ou `data/live/`) n'est pas accessible en écriture. Répare le propriétaire/les permissions :
```bash
sudo chown -R www-data:www-data /var/www/quiz/data
sudo chmod -R u+rwX /var/www/quiz/data
```

**Les fichiers dans `data/` sont accessibles depuis le navigateur.**
Tu n'as pas bloqué le répertoire. Sur Nginx ajoute le bloc `location ^~ /data/ { deny all; }` (section 6) ; sur Apache vérifie `AllowOverride All` ou bloque dans le vhost (section 5). Confirme avec une requête vers `.../data/.config.php` (doit être 403).

**« bad csrf » aux actions d'hôte.**
La session a expiré ou les cookies sont bloqués. Ré-authentifie-toi comme hôte. Vérifie aussi que les cookies de session fonctionnent (domaine/HTTPS correct).

**L'installation comme application (PWA) n'apparaît pas.**
Le PWA nécessite HTTPS. Configure un certificat (section 11).

**Les diacritiques/la correspondance à la réponse libre semblent bizarres.**
L'application dispose d'une solution de repli interne pour les diacritiques roumains même sans l'extension `intl`. Si tu vois quand même des problèmes, installer les extensions `php-intl` et `php-mbstring` peut aider :
```bash
sudo apt install php8.3-intl php8.3-mbstring && sudo systemctl reload php8.3-fpm
```

**Les sessions PHP ne persistent pas (ça te déconnecte souvent).**
Vérifie les réglages `session.save_path` dans `php.ini` et que ce répertoire est accessible en écriture ; vérifie l'horloge du serveur et la configuration des cookies.

---

## 17. Performance et limites

- **Sans base de données**, chaque réponse dans un jeu en direct implique une lecture-modification-écriture avec verrouillage du fichier de session. Pour des tailles d'atelier typiques (des dizaines de participants) c'est parfaitement adéquat.
- **Pour de très grands groupes** (plusieurs centaines de participants simultanément sur la même session), la contention sur le fichier de session peut augmenter la latence. L'application est pensée pour des ateliers, pas pour des événements de masse de type stade.
- **Limites par défaut** (pour des raisons de robustesse) : un maximum de **100 questions** par questionnaire, des longueurs maximales pour le titre/les questions/les réponses/les noms/les messages, une taille maximale de requête. Ces limites protègent le serveur de l'abus et des entrées accidentellement énormes.
- **Nettoie `data/live/`** périodiquement (section 12) pour éviter l'accumulation de fichiers.

---

## 18. Liste de contrôle avant un atelier

- [ ] `index.php` est sur le serveur et la page d'accueil se charge.
- [ ] L'accès web à `data/` est **bloqué** (vérifié avec une requête vers `.../data/.config.php`).
- [ ] **HTTPS** actif (nécessaire pour le PWA et la sécurité).
- [ ] Le mot de passe d'hôte est **défini** et tu le connais.
- [ ] Tu as fait un **jeu en direct d'essai de bout en bout** avec 2–3 appareils réels, sur le réseau que tu utiliseras, en testant **chaque type de question** (choix multiple, vrai-faux, réponse libre, numérique) et, si tu les utilises, les **équipes** et les **activités d'audience**.
- [ ] Tu as testé le **code d'entrée** et le **QR** depuis un téléphone dans la salle.
- [ ] Tu as un plan de secours (par ex. l'édition hors ligne HTML) en cas de problèmes de réseau.
- [ ] La tâche de **nettoyage** des sessions est configurée (cron) ou tu sais la faire manuellement.
- [ ] Tu as une **sauvegarde** récente de `data/`.

---

## 19. Une note honnête sur les tests

Cette application a été développée et vérifiée intensivement au niveau de la **logique** (formules de score, correspondance de texte, agrégation, validations), mais le **flux intégré de bout en bout sur un vrai serveur, dans le navigateur, avec plusieurs appareils** est ta responsabilité à valider avant de t'y fier publiquement. En particulier :

- **Lance un jeu en direct complet** sur ton infrastructure réelle avant un atelier — c'est là que ressortent d'éventuels problèmes d'intégration qui n'apparaissent pas lors des tests par composants.
- **Teste les combinaisons** que tu utiliseras effectivement (par ex. présentation avec Q&R modéré + filtre, équipes + questions numériques, ré-entrée hors ligne dans le PWA).
- **Vérifie les en-têtes et les cookies de session** si tu exposes l'application sur internet, pas seulement sur un réseau local d'atelier.
- Rappelle-toi que le **filtre de langage est faible** — fie-toi à la modération des Q&R et à une surveillance humaine pour le contenu public.

Traite-la comme un outil solide, mais **teste-la toi-même de bout en bout** dans tes conditions avant de la mettre devant un public.

---

*Undava — l'édition serveur. Pour l'utilisation proprement dite (création de questionnaires, jeu, activités), voir le **Manuel de l'utilisateur**.*
</script>
<script type="text/markdown" id="manual-admin-it">
# Undava — Manuale dell'amministratore

*Dall'installazione alla manutenzione. Per l'edizione server (`index.php`).*

---

## Indice

1. [Architettura in breve](#1-architettura)
2. [Requisiti di sistema](#2-requisiti-di-sistema)
3. [L'edizione offline (HTML) — «installazione»](#3-edizione-offline)
4. [Installare l'edizione server — panoramica](#4-installare-server)
5. [Installazione con Apache](#5-apache)
6. [Installazione con Nginx + PHP-FPM](#6-nginx)
7. [Installazione rapida per test (`php -S`)](#7-test-rapido)
8. [Prima configurazione: la password del conduttore](#8-password-conduttore)
9. [Struttura dei file e dei dati](#9-struttura-file)
10. [Sicurezza — lettura obbligatoria](#10-sicurezza)
11. [HTTPS e PWA](#11-https-pwa)
12. [Manutenzione](#12-manutenzione)
13. [Backup e ripristino](#13-backup-ripristino)
14. [Aggiornamento dell'applicazione](#14-aggiornamento)
15. [Reimpostare la password del conduttore](#15-reimpostare-password)
16. [Risoluzione dei problemi](#16-risoluzione-problemi)
17. [Prestazioni e limiti](#17-prestazioni-limiti)
18. [Lista di controllo prima di un laboratorio](#18-lista-controllo)
19. [Una nota onesta sullo stato dei test](#19-nota-test)

---

## 1. Architettura in breve

- **Un unico file**: `index.php`. Contiene il server (PHP), l'API e l'interfaccia (HTML/JS) insieme.
- **Senza database.** Archiviazione su **file piatti** (flat-file) in una cartella `data/` creata automaticamente accanto a `index.php`.
- **Senza dipendenze esterne.** Non ha bisogno di Composer, npm, librerie o servizi esterni. Solo PHP.
- **Stato**: le sessioni di gioco/attività sono file JSON in `data/live/`. La configurazione (l'hash della password del conduttore) è in `data/.config.php`.
- **Autenticazione**: un solo ruolo privilegiato — il **conduttore/amministratore** — protetto con una password (hash `password_hash`) e un token **CSRF** per sessione. I partecipanti ai giochi non hanno bisogno di un account.

---

## 2. Requisiti di sistema

- **PHP 8.0 o più recente** (testato su **PHP 8.3.6**). Consigliato 8.1+.
  - Estensioni necessarie: quelle standard (`json`, `session`, preferibilmente `mbstring`). Non servono estensioni esotiche. `intl`/`Normalizer` **non** sono obbligatorie — l'applicazione ha una riserva per i segni diacritici rumeni.
- Un **server web** che esegue PHP: Apache (mod_php o PHP-FPM), Nginx + PHP-FPM, o il server integrato `php -S` (solo per test).
- **Spazio su disco**: l'applicazione in sé è ~350 KB. I dati crescono con il numero di sessioni (ogni gioco = un file JSON dell'ordine di decine–centinaia di KB).
- **Permessi**: la cartella in cui metti `index.php` deve essere **scrivibile dall'utente del server web** (così può creare `data/`).
- **HTTPS**: fortemente consigliato (obbligatorio per il PWA e per proteggere la password/il cookie di sessione). Vedi la sezione 11.

---

## 3. L'edizione offline (HTML)

`quiz-fara-frontiere.html` non richiede installazione:

- Puoi semplicemente distribuirlo (email, chiavetta USB, condivisione di file) e chiunque lo apre con un doppio clic.
- Facoltativamente, puoi anche servirlo da un server web come file statico, ma non è necessario.
- Non crea file sul server; i dati restano nel browser di ogni utente.

Il resto di questo manuale riguarda l'**edizione server** (`index.php`).

---

## 4. Installare l'edizione server

Passi generali (dettagliati per ogni server sotto):

1. Copia `index.php` nella cartella desiderata della radice web (la radice del sito o una sottocartella, ad es. `/var/www/quiz/`).
2. Assicurati che l'**utente del server web** (`www-data` su Debian/Ubuntu) possa **scrivere** in quella cartella, così l'applicazione può creare `data/` da sola.
3. Configura il server web per eseguire PHP e per **bloccare l'accesso web a `data/`**.
4. Apri l'indirizzo in un browser e **imposta la password del conduttore** (sezione 8).

> La cartella `data/` (con le sottocartelle `data/live/` e `data/quizzes/`, più i file di protezione `.htaccess` e `index.html`) viene creata **automaticamente** al primo accesso, se i permessi lo consentono.

---

## 5. Installazione con Apache

Supponiamo Ubuntu/Debian con Apache e PHP.

### 5.1. Copia il file e imposta i permessi

```bash
sudo mkdir -p /var/www/quiz
sudo cp index.php /var/www/quiz/
sudo chown -R www-data:www-data /var/www/quiz
sudo chmod 755 /var/www/quiz
```

### 5.2. Assicura l'esecuzione di PHP

- Con **mod_php**: verifica `sudo a2enmod php8.3` (o la tua versione) e riavvia Apache.
- Con **PHP-FPM**: `sudo a2enmod proxy_fcgi setenvif && sudo a2enconf php8.3-fpm`.

### 5.3. Consenti `.htaccess` (per la protezione di `data/`)

L'applicazione scrive automaticamente `data/.htaccess` con `Require all denied`. Perché questo abbia effetto, il tuo vhost deve consentire le sostituzioni in quella cartella. Nella configurazione del sito (ad es. `/etc/apache2/sites-available/000-default.conf`):

```apache
<Directory /var/www/quiz>
    AllowOverride All
    Require all granted
</Directory>
```

Oppure, in modo più sicuro, **blocca esplicitamente** `data/` direttamente nel vhost (indipendentemente da `.htaccess`):

```apache
<Directory /var/www/quiz/data>
    Require all denied
</Directory>
```

Riavvia: `sudo systemctl reload apache2`.

### 5.4. Testa

Apri `http://il-tuo-server/quiz/`. Dovresti vedere la schermata iniziale. Verifica poi che `http://il-tuo-server/quiz/data/.config.php` **non** sia accessibile (deve restituire 403 o contenuto vuoto).

---

## 6. Installazione con Nginx + PHP-FPM

Nginx **non** legge `.htaccess`, quindi la protezione della cartella `data/` deve essere configurata manualmente — **questo passo è obbligatorio**.

### 6.1. Copia il file e imposta i permessi

```bash
sudo mkdir -p /var/www/quiz
sudo cp index.php /var/www/quiz/
sudo chown -R www-data:www-data /var/www/quiz
sudo chmod 755 /var/www/quiz
```

### 6.2. Configura il sito

Esempio di blocco server (adatta il percorso del socket PHP-FPM alla tua versione):

```nginx
server {
    listen 80;
    server_name esempio.com;
    root /var/www/quiz;
    index index.php;

    # BLOCCA l'accesso web alla cartella dei dati (OBBLIGATORIO)
    location ^~ /data/ {
        deny all;
        return 403;
    }

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

### 6.3. Testa e riavvia

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Apri `http://esempio.com/` e poi verifica che `http://esempio.com/data/.config.php` restituisca **403**.

---

## 7. Installazione rapida per test

Per prove locali (non per la produzione):

```bash
cd /percorso/verso/la-cartella-con-index.php
php -S 0.0.0.0:8080
```

Poi apri `http://localhost:8080/`. Il server integrato esegue PHP e crea `data/` nella cartella corrente.

> ⚠️ Il server `php -S` è **solo per lo sviluppo**. Non esporlo su internet e non usarlo per un laboratorio reale — non ha le protezioni e la robustezza di un vero server web.

---

## 8. Prima configurazione: la password del conduttore

Al primo utilizzo non c'è alcuna password impostata. La prima persona che vuole **creare** giochi deve impostare la password del conduttore:

1. Apri l'indirizzo dell'applicazione.
2. Entra nell'area conduttore / autenticazione.
3. Poiché non c'è ancora una password, ti viene chiesto di **impostarne** una. Inserisci una password di **almeno 6 caratteri** (consigliata molto più lunga e unica).
4. La password viene **sottoposta a hash** (`password_hash`, l'algoritmo predefinito di PHP) e salvata in `data/.config.php`. Il testo della password **non** viene memorizzato da nessuna parte.
5. Dopo averla impostata, sei automaticamente autenticato come conduttore.

**Autenticazioni successive:** inserisci la password; l'applicazione la verifica con `password_verify`. In caso di successo, la tua sessione riceve i diritti di conduttore e un token CSRF. C'è un piccolo ritardo in caso di password errata, per scoraggiare l'indovinare con forza bruta.

**Disconnessione:** il pulsante di disconnessione cancella i diritti di conduttore dalla sessione.

> La password protegge la **creazione e il controllo** dei giochi/attività. I partecipanti non ne hanno bisogno. Scegli una password che puoi condividere in sicurezza con i co-organizzatori, se necessario.

---

## 9. Struttura dei file e dei dati

Tutto sta accanto a `index.php`, nella cartella `data/` (auto-creata):

```
index.php                  ← l'applicazione (file unico)
data/                      ← cartella dei dati (auto-creata, NON PUBBLICA)
├── .config.php            ← configurazione: hash password conduttore, flag moderazione (SENSIBILE)
├── .htaccess              ← blocca l'accesso web (solo Apache)
├── index.html             ← segnaposto 403, anti-elenco
├── feedback.txt           ← messaggi dal «libro degli ospiti» (una riga/record)
├── quizzes/               ← quiz memorizzati sul server (se necessario)
└── live/                  ← sessioni live: un file JSON per gioco/attività
    ├── ABCD.json
    ├── EFGH.json
    └── ...
```

Punti importanti:

- **`data/.config.php`** contiene l'hash della password — trattalo come un segreto. Essendo un file `.php`, anche se acceduto via web, restituisce contenuto vuoto (si esegue, non si mostra). Ciononostante, proteggi la cartella `data/` (vedi sicurezza).
- **`data/live/*.json`** sono le sessioni di gioco. Si **accumulano** — ogni gioco live o attività crea un file. Devono essere **pulite periodicamente** (sezione 12).
- **Le scritture sono atomiche** (file temporaneo + rinomina) e **serializzate** (blocco con `flock`), quindi non si corrompono con accessi simultanei.
- I codici di sessione sono brevi (4 caratteri da `A–Z0–9`, senza caratteri ambigui) e rigorosamente convalidati — non possono contenere percorsi di file.

---

## 10. Sicurezza

L'applicazione è progettata in modo difensivo, ma **alcune misure dipendono da te, l'amministratore.**

### Cosa fa l'applicazione da sola

- **Escaping coerente** dei dati degli utenti alla visualizzazione (protezione XSS).
- **Sanificazione rigorosa** dei codici/ID usati nei percorsi di file (nessun path traversal).
- **CSRF**: le azioni del conduttore (creazione/controllo) richiedono un token CSRF verificato con `hash_equals`.
- **Rigenerazione dell'ID di sessione** all'autenticazione (anti fissazione di sessione).
- **Limitazione della dimensione delle richieste** (rifiuta payload troppo grandi) e **limite massimo** degli input (numero di domande, lunghezze di testo, valori di valutazione, ecc.).
- **Limitazione di frequenza** (rate-limiting) sugli invii e sulle reazioni.

### Cosa devi fare TU

1. **Blocca l'accesso web a `data/`** — obbligatorio su Nginx (sezione 6), verificato su Apache (sezione 5). Conferma manualmente che `.../data/.config.php` restituisca 403.
2. **Attiva HTTPS** (sezione 11). Senza di esso, la password del conduttore e il cookie di sessione circolano in chiaro.
3. **Rafforza il cookie di sessione.** L'applicazione usa le impostazioni predefinite di PHP. Consigliato, in `php.ini` (o nella configurazione del pool PHP-FPM):

   ```ini
   session.cookie_httponly = 1
   session.cookie_secure   = 1   ; richiede HTTPS
   session.cookie_samesite = Lax
   session.use_strict_mode = 1
   ```

4. **Aggiungi intestazioni di sicurezza** a livello di server web (l'applicazione non le imposta da sola). Esempio minimo (Nginx):

   ```nginx
   add_header X-Content-Type-Options "nosniff" always;
   add_header X-Frame-Options "SAMEORIGIN" always;
   add_header Referrer-Policy "strict-origin-when-cross-origin" always;
   # Una politica CSP adeguata richiede test, perché l'interfaccia usa stili/script inline.
   ```

   > Nota: l'interfaccia usa JS e CSS **inline**, quindi una CSP rigorosa con `script-src` restrittivo la bloccherà. Se vuoi una CSP, testa con attenzione (possibilmente `'unsafe-inline'` per iniziare, poi affina).

5. **Restringi l'accesso se necessario.** Se il server è solo per un laboratorio interno, puoi limitare l'accesso a una rete/VPN o aggiungere autenticazione a livello di server.

### Limitazione nota: il filtro di linguaggio

Il filtro opzionale del linguaggio volgare (sulle attività pubbliche) è **di base** e facile da aggirare (scrittura «l33t», spaziatura, omoglifi). Trattalo come un aiuto cosmetico, **non** come una moderazione affidabile. Per un controllo reale del contenuto pubblico, usa la **moderazione Q&A** (approvazione prima della visualizzazione) e la supervisione umana.

---

## 11. HTTPS e PWA

- **HTTPS** è necessario per: l'installazione come app (PWA), `session.cookie_secure`, e la protezione della password del conduttore.
- La via più semplice su Ubuntu: **Let's Encrypt** con Certbot.

  ```bash
  sudo apt install certbot python3-certbot-nginx   # oppure -apache
  sudo certbot --nginx -d esempio.com              # oppure --apache
  ```

- **PWA**: l'applicazione serve da sola il manifesto e il service worker (tramite parametri interni del tipo `?asset=...`). Una volta su HTTPS, gli utenti possono installare l'applicazione dal browser. Non serve configurazione aggiuntiva.

---

## 12. Manutenzione

### Pulizia delle vecchie sessioni (consigliato)

I file in `data/live/` si accumulano. Elimina periodicamente quelli vecchi. Esempio di attività cron che elimina le sessioni non modificate da oltre **24 ore**:

```bash
# modifica la crontab dell'utente del server web
sudo -u www-data crontab -e
```

Aggiungi una riga (adatta il percorso):

```cron
0 3 * * * find /var/www/quiz/data/live -name '*.json' -mmin +1440 -delete
```

Questa viene eseguita quotidianamente alle 03:00 ed elimina le sessioni più vecchie di 1440 minuti (24 h). Adatta la soglia alle tue esigenze (ad es. `-mmin +720` per 12 h).

> Non eliminare `data/.config.php` (la password) e `data/feedback.txt` se non intenzionalmente.

### Monitoraggio dello spazio su disco

```bash
du -sh /var/www/quiz/data
ls -1 /var/www/quiz/data/live | wc -l   # quante sessioni attive/residue
```

### Verifica dei permessi

Se l'applicazione non può scrivere (i giochi non si creano), verifica:

```bash
ls -ld /var/www/quiz /var/www/quiz/data
sudo chown -R www-data:www-data /var/www/quiz/data
```

---

## 13. Backup e ripristino

Cosa conta per il backup: **`data/.config.php`** (la password) e, se usi l'archiviazione server dei quiz, **`data/quizzes/`**. Le sessioni live (`data/live/`) sono di solito effimere e non necessitano di backup.

### Backup

```bash
sudo tar czf quiz-backup-$(date +%F).tar.gz -C /var/www/quiz data
```

Conserva l'archivio in un luogo sicuro (contiene l'hash della password).

### Ripristino

```bash
sudo tar xzf quiz-backup-2026-07-01.tar.gz -C /var/www/quiz
sudo chown -R www-data:www-data /var/www/quiz/data
```

---

## 14. Aggiornamento dell'applicazione

Essendo un unico file, l'aggiornamento è semplice:

1. **Fai il backup** di `data/` (sezione 13) e conserva una copia del vecchio `index.php`.
2. Sostituisci `index.php` con la nuova versione.
3. Ripara il proprietario se necessario: `sudo chown www-data:www-data /var/www/quiz/index.php`.
4. Ricarica la pagina e **testa** un gioco di prova.

I dati in `data/` (inclusa la password) **vengono conservati** durante l'aggiornamento, perché sono separati da `index.php`. Il formato dei quiz è stabile tra le versioni, quindi i quiz esistenti restano compatibili.

---

## 15. Reimpostare la password del conduttore

Se hai dimenticato la password:

1. Fai il backup di `data/`.
2. **Elimina** (o rinomina) il file di configurazione:

   ```bash
   sudo mv /var/www/quiz/data/.config.php /var/www/quiz/data/.config.php.bak
   ```

3. Ricarica l'applicazione. Poiché non c'è più una password impostata, potrai **impostarne una nuova** proprio come alla prima configurazione (sezione 8).

> Questo reimposta solo la password; **non** influisce sui quiz o sulle sessioni. Il vecchio file `.config.php.bak` contiene solo il vecchio hash — eliminalo dopo esserti assicurato che tutto sia a posto.

---

## 16. Risoluzione dei problemi

**La pagina è bianca / errore 500.**
Verifica il log degli errori PHP/del server:
- Apache: `sudo tail -f /var/log/apache2/error.log`
- Nginx + PHP-FPM: `sudo tail -f /var/log/nginx/error.log` e `/var/log/php8.3-fpm.log`
Cause frequenti: versione di PHP troppo vecchia (<8.0), permessi errati sulla cartella.

**I giochi/le attività non si creano («write failed»).**
La cartella `data/` (o `data/live/`) non è scrivibile. Ripara il proprietario/i permessi:
```bash
sudo chown -R www-data:www-data /var/www/quiz/data
sudo chmod -R u+rwX /var/www/quiz/data
```

**I file in `data/` sono accessibili dal browser.**
Non hai bloccato la cartella. Su Nginx aggiungi il blocco `location ^~ /data/ { deny all; }` (sezione 6); su Apache verifica `AllowOverride All` o blocca nel vhost (sezione 5). Conferma con una richiesta a `.../data/.config.php` (deve essere 403).

**«bad csrf» nelle azioni del conduttore.**
La sessione è scaduta o i cookie sono bloccati. Riautenticati come conduttore. Verifica anche che i cookie di sessione funzionino (dominio/HTTPS corretto).

**L'installazione come app (PWA) non appare.**
Il PWA richiede HTTPS. Configura un certificato (sezione 11).

**I diacritici/la corrispondenza alla risposta libera sembrano strani.**
L'applicazione ha una riserva interna per i segni diacritici rumeni anche senza l'estensione `intl`. Se vedi comunque problemi, installare le estensioni `php-intl` e `php-mbstring` può aiutare:
```bash
sudo apt install php8.3-intl php8.3-mbstring && sudo systemctl reload php8.3-fpm
```

**Le sessioni PHP non persistono (ti disconnette spesso).**
Verifica le impostazioni `session.save_path` in `php.ini` e che quella cartella sia scrivibile; verifica l'orologio del server e la configurazione dei cookie.

---

## 17. Prestazioni e limiti

- **Senza database**, ogni risposta in un gioco live implica una lettura-modifica-scrittura con blocco del file di sessione. Per dimensioni tipiche di laboratorio (decine di partecipanti) è perfettamente adeguato.
- **Per gruppi molto grandi** (molte centinaia di partecipanti simultaneamente sulla stessa sessione), la contesa sul file di sessione può aumentare la latenza. L'applicazione è pensata per laboratori, non per eventi di massa di tipo stadio.
- **Limiti predefiniti** (per motivi di robustezza): un massimo di **100 domande** per quiz, lunghezze massime per titolo/domande/risposte/nomi/messaggi, dimensione massima della richiesta. Questi limiti proteggono il server dall'abuso e da input accidentalmente enormi.
- **Pulisci `data/live/`** periodicamente (sezione 12) per evitare l'accumulo di file.

---

## 18. Lista di controllo prima di un laboratorio

- [ ] `index.php` è sul server e la pagina iniziale si carica.
- [ ] L'accesso web a `data/` è **bloccato** (verificato con una richiesta a `.../data/.config.php`).
- [ ] **HTTPS** attivo (necessario per il PWA e la sicurezza).
- [ ] La password del conduttore è **impostata** e la conosci.
- [ ] Hai fatto un **gioco live di prova da capo a fondo** con 2–3 dispositivi reali, sulla rete che userai, testando **ogni tipo di domanda** (scelta multipla, vero-falso, risposta libera, numerica) e, se le usi, le **squadre** e le **attività per il pubblico**.
- [ ] Hai testato il **codice d'ingresso** e il **QR** da un telefono nella sala.
- [ ] Hai un piano di riserva (ad es. l'edizione offline HTML) in caso di problemi di rete.
- [ ] L'attività di **pulizia** delle sessioni è configurata (cron) o sai farla manualmente.
- [ ] Hai un **backup** recente di `data/`.

---

## 19. Una nota onesta sui test

Questa applicazione è stata sviluppata e verificata intensamente a livello di **logica** (formule di punteggio, corrispondenza di testo, aggregazione, convalide), ma il **flusso integrato da capo a fondo su un server reale, nel browser, con più dispositivi** è tua responsabilità convalidarlo prima di affidartici pubblicamente. In particolare:

- **Esegui un gioco live completo** sulla tua infrastruttura reale prima di un laboratorio — è qui che emergono eventuali problemi di integrazione che non appaiono nei test sui componenti.
- **Testa le combinazioni** che userai effettivamente (ad es. presentazione con Q&A moderato + filtro, squadre + domande numeriche, rientro offline nel PWA).
- **Verifica le intestazioni e i cookie di sessione** se esponi l'applicazione su internet, non solo su una rete locale di laboratorio.
- Ricorda che il **filtro di linguaggio è debole** — affidati alla moderazione Q&A e alla supervisione umana per il contenuto pubblico.

Trattala come uno strumento solido, ma **testala tu da capo a fondo** nelle tue condizioni prima di metterla davanti a un pubblico.

---

*Undava — l'edizione server. Per l'uso vero e proprio (creazione di quiz, gioco, attività), vedi il **Manuale utente**.*
</script>
<script type="text/markdown" id="manual-admin-es">
# Undava — Manual del administrador

*De la instalación al mantenimiento. Para la edición servidor (`index.php`).*

---

## Índice

1. [Arquitectura en breve](#1-arquitectura)
2. [Requisitos del sistema](#2-requisitos-del-sistema)
3. [La edición offline (HTML) — «instalación»](#3-edicion-offline)
4. [Instalar la edición servidor — visión general](#4-instalar-servidor)
5. [Instalación con Apache](#5-apache)
6. [Instalación con Nginx + PHP-FPM](#6-nginx)
7. [Instalación rápida para pruebas (`php -S`)](#7-prueba-rapida)
8. [Primera configuración: la contraseña de anfitrión](#8-contrasena-anfitrion)
9. [Estructura de archivos y datos](#9-estructura-archivos)
10. [Seguridad — lectura obligatoria](#10-seguridad)
11. [HTTPS y PWA](#11-https-pwa)
12. [Mantenimiento](#12-mantenimiento)
13. [Copia de seguridad y restauración](#13-copia-restauracion)
14. [Actualización de la aplicación](#14-actualizacion)
15. [Restablecer la contraseña de anfitrión](#15-restablecer-contrasena)
16. [Resolución de problemas](#16-resolucion-problemas)
17. [Rendimiento y límites](#17-rendimiento-limites)
18. [Lista de comprobación antes de un taller](#18-lista-comprobacion)
19. [Una nota honesta sobre el estado de las pruebas](#19-nota-pruebas)

---

## 1. Arquitectura en breve

- **Un único archivo**: `index.php`. Contiene el servidor (PHP), la API y la interfaz (HTML/JS) juntos.
- **Sin base de datos.** Almacenamiento en **archivos planos** (flat-file) en un directorio `data/` creado automáticamente junto a `index.php`.
- **Sin dependencias externas.** No necesita Composer, npm, bibliotecas ni servicios externos. Solo PHP.
- **Estado**: las sesiones de juego/actividades son archivos JSON en `data/live/`. La configuración (el hash de la contraseña de anfitrión) está en `data/.config.php`.
- **Autenticación**: un solo rol privilegiado — el **anfitrión/administrador** — protegido con una contraseña (hash `password_hash`) y un token **CSRF** por sesión. Los participantes en los juegos no necesitan cuenta.

---

## 2. Requisitos del sistema

- **PHP 8.0 o más reciente** (probado en **PHP 8.3.6**). Recomendado 8.1+.
  - Extensiones necesarias: las estándar (`json`, `session`, preferiblemente `mbstring`). No se necesitan extensiones exóticas. `intl`/`Normalizer` **no** son obligatorias — la aplicación tiene una reserva para los signos diacríticos rumanos.
- Un **servidor web** que ejecute PHP: Apache (mod_php o PHP-FPM), Nginx + PHP-FPM, o el servidor integrado `php -S` (solo para pruebas).
- **Espacio en disco**: la aplicación en sí es ~350 KB. Los datos crecen con el número de sesiones (cada juego = un archivo JSON del orden de decenas–centenas de KB).
- **Permisos**: el directorio donde pones `index.php` debe tener **permisos de escritura para el usuario del servidor web** (para que pueda crear `data/`).
- **HTTPS**: muy recomendado (obligatorio para el PWA y para proteger la contraseña/la cookie de sesión). Ver la sección 11.

---

## 3. La edición offline (HTML)

`quiz-fara-frontiere.html` no requiere instalación:

- Puedes simplemente distribuirlo (correo, memoria USB, compartición de archivos) y cualquiera lo abre con doble clic.
- Opcionalmente, también puedes servirlo desde un servidor web como archivo estático, pero no es necesario.
- No crea archivos en el servidor; los datos permanecen en el navegador de cada usuario.

El resto de este manual se refiere a la **edición servidor** (`index.php`).

---

## 4. Instalar la edición servidor

Pasos generales (detallados por servidor más abajo):

1. Copia `index.php` en el directorio deseado de la raíz web (la raíz del sitio o un subdirectorio, p. ej. `/var/www/quiz/`).
2. Asegúrate de que el **usuario del servidor web** (`www-data` en Debian/Ubuntu) pueda **escribir** en ese directorio, para que la aplicación pueda crear `data/` por sí misma.
3. Configura el servidor web para ejecutar PHP y para **bloquear el acceso web a `data/`**.
4. Abre la dirección en un navegador y **establece la contraseña de anfitrión** (sección 8).

> El directorio `data/` (con los subdirectorios `data/live/` y `data/quizzes/`, más los archivos de protección `.htaccess` e `index.html`) se crea **automáticamente** en el primer acceso, si los permisos lo permiten.

---

## 5. Instalación con Apache

Suponemos Ubuntu/Debian con Apache y PHP.

### 5.1. Copia el archivo y establece los permisos

```bash
sudo mkdir -p /var/www/quiz
sudo cp index.php /var/www/quiz/
sudo chown -R www-data:www-data /var/www/quiz
sudo chmod 755 /var/www/quiz
```

### 5.2. Asegura la ejecución de PHP

- Con **mod_php**: verifica `sudo a2enmod php8.3` (o tu versión) y reinicia Apache.
- Con **PHP-FPM**: `sudo a2enmod proxy_fcgi setenvif && sudo a2enconf php8.3-fpm`.

### 5.3. Permite `.htaccess` (para la protección de `data/`)

La aplicación escribe automáticamente `data/.htaccess` con `Require all denied`. Para que esto surta efecto, tu vhost debe permitir las anulaciones en ese directorio. En la configuración del sitio (p. ej. `/etc/apache2/sites-available/000-default.conf`):

```apache
<Directory /var/www/quiz>
    AllowOverride All
    Require all granted
</Directory>
```

O, de forma más segura, **bloquea explícitamente** `data/` directamente en el vhost (independientemente de `.htaccess`):

```apache
<Directory /var/www/quiz/data>
    Require all denied
</Directory>
```

Reinicia: `sudo systemctl reload apache2`.

### 5.4. Prueba

Abre `http://tu-servidor/quiz/`. Deberías ver la pantalla de inicio. Comprueba luego que `http://tu-servidor/quiz/data/.config.php` **no** sea accesible (debe devolver 403 o contenido vacío).

---

## 6. Instalación con Nginx + PHP-FPM

Nginx **no** lee `.htaccess`, así que la protección del directorio `data/` debe configurarse manualmente — **este paso es obligatorio**.

### 6.1. Copia el archivo y establece los permisos

```bash
sudo mkdir -p /var/www/quiz
sudo cp index.php /var/www/quiz/
sudo chown -R www-data:www-data /var/www/quiz
sudo chmod 755 /var/www/quiz
```

### 6.2. Configura el sitio

Ejemplo de bloque de servidor (ajusta la ruta del socket PHP-FPM a tu versión):

```nginx
server {
    listen 80;
    server_name ejemplo.com;
    root /var/www/quiz;
    index index.php;

    # BLOQUEA el acceso web al directorio de datos (OBLIGATORIO)
    location ^~ /data/ {
        deny all;
        return 403;
    }

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

### 6.3. Prueba y reinicia

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Abre `http://ejemplo.com/` y comprueba luego que `http://ejemplo.com/data/.config.php` devuelva **403**.

---

## 7. Instalación rápida para pruebas

Para pruebas locales (no para producción):

```bash
cd /ruta/hacia/la-carpeta-con-index.php
php -S 0.0.0.0:8080
```

Luego abre `http://localhost:8080/`. El servidor integrado ejecuta PHP y crea `data/` en la carpeta actual.

> ⚠️ El servidor `php -S` es **solo para desarrollo**. No lo expongas en internet y no lo uses para un taller real — no tiene las protecciones ni la robustez de un servidor web real.

---

## 8. Primera configuración: la contraseña de anfitrión

En el primer uso no hay ninguna contraseña establecida. La primera persona que quiera **crear** juegos debe establecer la contraseña de anfitrión:

1. Abre la dirección de la aplicación.
2. Entra en la zona de anfitrión / autenticación.
3. Como aún no hay contraseña, se te pide **establecer** una. Introduce una contraseña de **al menos 6 caracteres** (recomendada mucho más larga y única).
4. La contraseña se somete a **hash** (`password_hash`, el algoritmo predeterminado de PHP) y se guarda en `data/.config.php`. El texto de la contraseña **no** se almacena en ninguna parte.
5. Tras establecerla, quedas automáticamente autenticado como anfitrión.

**Autenticaciones posteriores:** introduces la contraseña; la aplicación la verifica con `password_verify`. En caso de éxito, tu sesión recibe los derechos de anfitrión y un token CSRF. Hay un pequeño retraso ante una contraseña incorrecta, para desalentar la adivinación por fuerza bruta.

**Cierre de sesión:** el botón de cierre de sesión borra los derechos de anfitrión de la sesión.

> La contraseña protege la **creación y el control** de los juegos/actividades. Los participantes no la necesitan. Elige una contraseña que puedas compartir de forma segura con los coorganizadores, si procede.

---

## 9. Estructura de archivos y datos

Todo está junto a `index.php`, en el directorio `data/` (autocreado):

```
index.php                  ← la aplicación (archivo único)
data/                      ← directorio de datos (autocreado, NO PÚBLICO)
├── .config.php            ← configuración: hash contraseña anfitrión, indicador moderación (SENSIBLE)
├── .htaccess              ← bloquea el acceso web (solo Apache)
├── index.html             ← marcador de posición 403, anti-listado
├── feedback.txt           ← mensajes del «libro de visitas» (una línea/registro)
├── quizzes/               ← cuestionarios almacenados en el servidor (si procede)
└── live/                  ← sesiones en directo: un archivo JSON por juego/actividad
    ├── ABCD.json
    ├── EFGH.json
    └── ...
```

Puntos importantes:

- **`data/.config.php`** contiene el hash de la contraseña — trátalo como un secreto. Al ser un archivo `.php`, aunque se acceda por web, devuelve contenido vacío (se ejecuta, no se muestra). Aun así, protege el directorio `data/` (ver seguridad).
- **`data/live/*.json`** son las sesiones de juego. Se **acumulan** — cada juego en directo o actividad crea un archivo. Deben **limpiarse periódicamente** (sección 12).
- **Las escrituras son atómicas** (archivo temporal + renombrado) y **serializadas** (bloqueo con `flock`), así que no se corrompen con accesos simultáneos.
- Los códigos de sesión son cortos (4 caracteres de `A–Z0–9`, sin caracteres ambiguos) y estrictamente validados — no pueden contener rutas de archivo.

---

## 10. Seguridad

La aplicación está diseñada de forma defensiva, pero **algunas medidas dependen de ti, el administrador.**

### Qué hace la aplicación por sí misma

- **Escapado coherente** de los datos de los usuarios al mostrarlos (protección XSS).
- **Saneamiento estricto** de los códigos/ID usados en rutas de archivo (sin path traversal).
- **CSRF**: las acciones de anfitrión (creación/control) requieren un token CSRF verificado con `hash_equals`.
- **Regeneración del ID de sesión** en la autenticación (anti fijación de sesión).
- **Limitación del tamaño de las solicitudes** (rechaza cargas útiles demasiado grandes) y **tope** de las entradas (número de preguntas, longitudes de texto, valores de valoración, etc.).
- **Limitación de frecuencia** (rate-limiting) en los envíos y en las reacciones.

### Qué debes hacer TÚ

1. **Bloquea el acceso web a `data/`** — obligatorio en Nginx (sección 6), verificado en Apache (sección 5). Confirma manualmente que `.../data/.config.php` devuelva 403.
2. **Activa HTTPS** (sección 11). Sin él, la contraseña de anfitrión y la cookie de sesión circulan en claro.
3. **Refuerza la cookie de sesión.** La aplicación usa la configuración predeterminada de PHP. Recomendado, en `php.ini` (o en la configuración del pool PHP-FPM):

   ```ini
   session.cookie_httponly = 1
   session.cookie_secure   = 1   ; requiere HTTPS
   session.cookie_samesite = Lax
   session.use_strict_mode = 1
   ```

4. **Añade cabeceras de seguridad** a nivel del servidor web (la aplicación no las establece por sí misma). Ejemplo mínimo (Nginx):

   ```nginx
   add_header X-Content-Type-Options "nosniff" always;
   add_header X-Frame-Options "SAMEORIGIN" always;
   add_header Referrer-Policy "strict-origin-when-cross-origin" always;
   # Una política CSP adecuada requiere pruebas, porque la interfaz usa estilos/scripts en línea.
   ```

   > Nota: la interfaz usa JS y CSS **en línea**, así que una CSP estricta con un `script-src` restrictivo la bloqueará. Si quieres una CSP, prueba con cuidado (posiblemente `'unsafe-inline'` para empezar, luego afina).

5. **Restringe el acceso si es necesario.** Si el servidor es solo para un taller interno, puedes limitar el acceso a una red/VPN o añadir autenticación a nivel de servidor.

### Limitación conocida: el filtro de lenguaje

El filtro opcional de lenguaje soez (en las actividades públicas) es **básico** y fácil de eludir (escritura «l33t», espaciado, homoglifos). Trátalo como una ayuda cosmética, **no** como una moderación fiable. Para un control real del contenido público, usa la **moderación de preguntas y respuestas** (aprobación antes de mostrar) y la supervisión humana.

---

## 11. HTTPS y PWA

- **HTTPS** es necesario para: la instalación como aplicación (PWA), `session.cookie_secure`, y la protección de la contraseña de anfitrión.
- La vía más sencilla en Ubuntu: **Let's Encrypt** con Certbot.

  ```bash
  sudo apt install certbot python3-certbot-nginx   # o -apache
  sudo certbot --nginx -d ejemplo.com              # o --apache
  ```

- **PWA**: la aplicación sirve por sí misma el manifiesto y el service worker (mediante parámetros internos del tipo `?asset=...`). Una vez en HTTPS, los usuarios pueden instalar la aplicación desde el navegador. No se necesita configuración adicional.

---

## 12. Mantenimiento

### Limpieza de las sesiones antiguas (recomendado)

Los archivos en `data/live/` se acumulan. Elimina periódicamente los antiguos. Ejemplo de tarea cron que elimina las sesiones no modificadas desde hace más de **24 horas**:

```bash
# edita la crontab del usuario del servidor web
sudo -u www-data crontab -e
```

Añade una línea (ajusta la ruta):

```cron
0 3 * * * find /var/www/quiz/data/live -name '*.json' -mmin +1440 -delete
```

Esto se ejecuta a diario a las 03:00 y elimina las sesiones más antiguas de 1440 minutos (24 h). Ajusta el umbral a tus necesidades (p. ej. `-mmin +720` para 12 h).

> No elimines `data/.config.php` (la contraseña) ni `data/feedback.txt` salvo intencionadamente.

### Supervisión del espacio en disco

```bash
du -sh /var/www/quiz/data
ls -1 /var/www/quiz/data/live | wc -l   # cuántas sesiones activas/residuales
```

### Verificación de los permisos

Si la aplicación no puede escribir (los juegos no se crean), verifica:

```bash
ls -ld /var/www/quiz /var/www/quiz/data
sudo chown -R www-data:www-data /var/www/quiz/data
```

---

## 13. Copia de seguridad y restauración

Lo que importa para la copia de seguridad: **`data/.config.php`** (la contraseña) y, si usas el almacenamiento en servidor de los cuestionarios, **`data/quizzes/`**. Las sesiones en directo (`data/live/`) suelen ser efímeras y no necesitan copia de seguridad.

### Copia de seguridad

```bash
sudo tar czf quiz-backup-$(date +%F).tar.gz -C /var/www/quiz data
```

Guarda el archivo en un lugar seguro (contiene el hash de la contraseña).

### Restauración

```bash
sudo tar xzf quiz-backup-2026-07-01.tar.gz -C /var/www/quiz
sudo chown -R www-data:www-data /var/www/quiz/data
```

---

## 14. Actualización de la aplicación

Al ser un único archivo, la actualización es sencilla:

1. **Haz una copia de seguridad** de `data/` (sección 13) y conserva una copia del antiguo `index.php`.
2. Sustituye `index.php` por la nueva versión.
3. Repara el propietario si es necesario: `sudo chown www-data:www-data /var/www/quiz/index.php`.
4. Recarga la página y **prueba** un juego de ensayo.

Los datos en `data/` (incluida la contraseña) **se conservan** durante la actualización, porque están separados de `index.php`. El formato de los cuestionarios es estable entre versiones, así que los cuestionarios existentes siguen siendo compatibles.

---

## 15. Restablecer la contraseña de anfitrión

Si olvidaste la contraseña:

1. Haz una copia de seguridad de `data/`.
2. **Elimina** (o renombra) el archivo de configuración:

   ```bash
   sudo mv /var/www/quiz/data/.config.php /var/www/quiz/data/.config.php.bak
   ```

3. Recarga la aplicación. Como ya no hay contraseña establecida, podrás **establecer una nueva** igual que en la primera configuración (sección 8).

> Esto solo restablece la contraseña; **no** afecta a los cuestionarios ni a las sesiones. El antiguo archivo `.config.php.bak` contiene solo el hash antiguo — elimínalo después de asegurarte de que todo está en orden.

---

## 16. Resolución de problemas

**La página está en blanco / error 500.**
Verifica el registro de errores de PHP/del servidor:
- Apache: `sudo tail -f /var/log/apache2/error.log`
- Nginx + PHP-FPM: `sudo tail -f /var/log/nginx/error.log` y `/var/log/php8.3-fpm.log`
Causas frecuentes: versión de PHP demasiado antigua (<8.0), permisos incorrectos en el directorio.

**Los juegos/actividades no se crean («write failed»).**
El directorio `data/` (o `data/live/`) no tiene permisos de escritura. Repara el propietario/los permisos:
```bash
sudo chown -R www-data:www-data /var/www/quiz/data
sudo chmod -R u+rwX /var/www/quiz/data
```

**Los archivos de `data/` son accesibles desde el navegador.**
No has bloqueado el directorio. En Nginx añade el bloque `location ^~ /data/ { deny all; }` (sección 6); en Apache verifica `AllowOverride All` o bloquea en el vhost (sección 5). Confirma con una solicitud a `.../data/.config.php` (debe ser 403).

**«bad csrf» en las acciones de anfitrión.**
La sesión ha expirado o las cookies están bloqueadas. Reautentícate como anfitrión. Verifica también que las cookies de sesión funcionen (dominio/HTTPS correcto).

**La instalación como aplicación (PWA) no aparece.**
El PWA requiere HTTPS. Configura un certificado (sección 11).

**Los diacríticos/la coincidencia en la respuesta libre parecen extraños.**
La aplicación tiene una reserva interna para los signos diacríticos rumanos incluso sin la extensión `intl`. Si aun así ves problemas, instalar las extensiones `php-intl` y `php-mbstring` puede ayudar:
```bash
sudo apt install php8.3-intl php8.3-mbstring && sudo systemctl reload php8.3-fpm
```

**Las sesiones de PHP no persisten (te desconecta a menudo).**
Verifica la configuración `session.save_path` en `php.ini` y que ese directorio tenga permisos de escritura; verifica el reloj del servidor y la configuración de las cookies.

---

## 17. Rendimiento y límites

- **Sin base de datos**, cada respuesta en un juego en directo implica una lectura-modificación-escritura con bloqueo del archivo de sesión. Para tamaños típicos de taller (decenas de participantes) es perfectamente adecuado.
- **Para grupos muy grandes** (muchos cientos de participantes simultáneamente en la misma sesión), la contención sobre el archivo de sesión puede aumentar la latencia. La aplicación está pensada para talleres, no para eventos masivos de tipo estadio.
- **Límites predeterminados** (por motivos de robustez): un máximo de **100 preguntas** por cuestionario, longitudes máximas para el título/las preguntas/las respuestas/los nombres/los mensajes, tamaño máximo de la solicitud. Estos límites protegen el servidor del abuso y de entradas accidentalmente enormes.
- **Limpia `data/live/`** periódicamente (sección 12) para evitar la acumulación de archivos.

---

## 18. Lista de comprobación antes de un taller

- [ ] `index.php` está en el servidor y la página de inicio se carga.
- [ ] El acceso web a `data/` está **bloqueado** (verificado con una solicitud a `.../data/.config.php`).
- [ ] **HTTPS** activo (necesario para el PWA y la seguridad).
- [ ] La contraseña de anfitrión está **establecida** y la conoces.
- [ ] Has hecho un **juego en directo de ensayo de extremo a extremo** con 2–3 dispositivos reales, en la red que usarás, probando **cada tipo de pregunta** (opción múltiple, verdadero-falso, respuesta libre, numérica) y, si los usas, los **equipos** y las **actividades de audiencia**.
- [ ] Has probado el **código de entrada** y el **QR** desde un teléfono de la sala.
- [ ] Tienes un plan de reserva (p. ej. la edición offline HTML) en caso de problemas de red.
- [ ] La tarea de **limpieza** de las sesiones está configurada (cron) o sabes hacerla manualmente.
- [ ] Tienes una **copia de seguridad** reciente de `data/`.

---

## 19. Una nota honesta sobre las pruebas

Esta aplicación se desarrolló y verificó intensamente a nivel de **lógica** (fórmulas de puntuación, coincidencia de texto, agregación, validaciones), pero el **flujo integrado de extremo a extremo en un servidor real, en el navegador, con varios dispositivos** es tu responsabilidad validarlo antes de confiar en él públicamente. En particular:

- **Ejecuta un juego en directo completo** en tu infraestructura real antes de un taller — aquí es donde salen a la luz posibles problemas de integración que no aparecen en las pruebas por componentes.
- **Prueba las combinaciones** que usarás efectivamente (p. ej. presentación con preguntas y respuestas moderadas + filtro, equipos + preguntas numéricas, reingreso offline en el PWA).
- **Verifica las cabeceras y las cookies de sesión** si expones la aplicación en internet, no solo en una red local de taller.
- Recuerda que el **filtro de lenguaje es débil** — confía en la moderación de preguntas y respuestas y en la supervisión humana para el contenido público.

Trátala como una herramienta sólida, pero **pruébala tú de extremo a extremo** en tus condiciones antes de ponerla frente a un público.

---

*Undava — la edición servidor. Para el uso propiamente dicho (crear cuestionarios, jugar, actividades), ver el **Manual de usuario**.*
</script>
<script type="text/markdown" id="manual-admin-pt">
# Undava — Manual do administrador

*Da instalação à manutenção. Para a edição servidor (`index.php`).*

---

## Índice

1. [Arquitetura em resumo](#1-arquitetura)
2. [Requisitos do sistema](#2-requisitos-do-sistema)
3. [A edição offline (HTML) — «instalação»](#3-edicao-offline)
4. [Instalar a edição servidor — visão geral](#4-instalar-servidor)
5. [Instalação com Apache](#5-apache)
6. [Instalação com Nginx + PHP-FPM](#6-nginx)
7. [Instalação rápida para teste (`php -S`)](#7-teste-rapido)
8. [Primeira configuração: a palavra-passe de anfitrião](#8-palavra-passe-anfitriao)
9. [Estrutura dos ficheiros e dos dados](#9-estrutura-ficheiros)
10. [Segurança — leitura obrigatória](#10-seguranca)
11. [HTTPS e PWA](#11-https-pwa)
12. [Manutenção](#12-manutencao)
13. [Cópia de segurança e restauro](#13-copia-restauro)
14. [Atualização da aplicação](#14-atualizacao)
15. [Repor a palavra-passe de anfitrião](#15-repor-palavra-passe)
16. [Resolução de problemas](#16-resolucao-problemas)
17. [Desempenho e limites](#17-desempenho-limites)
18. [Lista de verificação antes de um workshop](#18-lista-verificacao)
19. [Uma nota honesta sobre o estado dos testes](#19-nota-testes)

---

## 1. Arquitetura em resumo

- **Um único ficheiro**: `index.php`. Contém o servidor (PHP), a API e a interface (HTML/JS) juntos.
- **Sem base de dados.** Armazenamento em **ficheiros planos** (flat-file) numa pasta `data/` criada automaticamente ao lado de `index.php`.
- **Sem dependências externas.** Não precisa de Composer, npm, bibliotecas nem serviços externos. Apenas PHP.
- **Estado**: as sessões de jogo/atividades são ficheiros JSON em `data/live/`. A configuração (o hash da palavra-passe de anfitrião) está em `data/.config.php`.
- **Autenticação**: um único papel privilegiado — o **anfitrião/administrador** — protegido com uma palavra-passe (hash `password_hash`) e um token **CSRF** por sessão. Os participantes nos jogos não precisam de conta.

---

## 2. Requisitos do sistema

- **PHP 8.0 ou mais recente** (testado em **PHP 8.3.6**). Recomendado 8.1+.
  - Extensões necessárias: as padrão (`json`, `session`, de preferência `mbstring`). Não são precisas extensões exóticas. `intl`/`Normalizer` **não** são obrigatórias — a aplicação tem uma reserva para os diacríticos romenos.
- Um **servidor web** que execute PHP: Apache (mod_php ou PHP-FPM), Nginx + PHP-FPM, ou o servidor integrado `php -S` (apenas para teste).
- **Espaço em disco**: a aplicação em si tem ~350 KB. Os dados crescem com o número de sessões (cada jogo = um ficheiro JSON da ordem de dezenas–centenas de KB).
- **Permissões**: a pasta onde colocas `index.php` tem de ter **permissão de escrita para o utilizador do servidor web** (para que possa criar `data/`).
- **HTTPS**: fortemente recomendado (obrigatório para o PWA e para proteger a palavra-passe/o cookie de sessão). Ver a secção 11.

---

## 3. A edição offline (HTML)

`quiz-fara-frontiere.html` não requer instalação:

- Podes simplesmente distribuí-lo (email, pen USB, partilha de ficheiros) e qualquer pessoa o abre com um duplo clique.
- Opcionalmente, também o podes servir a partir de um servidor web como ficheiro estático, mas não é necessário.
- Não cria ficheiros no servidor; os dados ficam no navegador de cada utilizador.

O resto deste manual refere-se à **edição servidor** (`index.php`).

---

## 4. Instalar a edição servidor

Passos gerais (detalhados por servidor abaixo):

1. Copia `index.php` para a pasta desejada da raiz web (a raiz do site ou uma subpasta, por ex. `/var/www/quiz/`).
2. Certifica-te de que o **utilizador do servidor web** (`www-data` em Debian/Ubuntu) pode **escrever** nessa pasta, para que a aplicação possa criar `data/` sozinha.
3. Configura o servidor web para executar PHP e para **bloquear o acesso web a `data/`**.
4. Abre o endereço num navegador e **define a palavra-passe de anfitrião** (secção 8).

> A pasta `data/` (com as subpastas `data/live/` e `data/quizzes/`, mais os ficheiros de proteção `.htaccess` e `index.html`) é criada **automaticamente** no primeiro acesso, se as permissões o permitirem.

---

## 5. Instalação com Apache

Assumimos Ubuntu/Debian com Apache e PHP.

### 5.1. Copia o ficheiro e define as permissões

```bash
sudo mkdir -p /var/www/quiz
sudo cp index.php /var/www/quiz/
sudo chown -R www-data:www-data /var/www/quiz
sudo chmod 755 /var/www/quiz
```

### 5.2. Assegura a execução do PHP

- Com **mod_php**: verifica `sudo a2enmod php8.3` (ou a tua versão) e reinicia o Apache.
- Com **PHP-FPM**: `sudo a2enmod proxy_fcgi setenvif && sudo a2enconf php8.3-fpm`.

### 5.3. Permite `.htaccess` (para a proteção de `data/`)

A aplicação escreve automaticamente `data/.htaccess` com `Require all denied`. Para que isto tenha efeito, o teu vhost tem de permitir as substituições nessa pasta. Na configuração do site (por ex. `/etc/apache2/sites-available/000-default.conf`):

```apache
<Directory /var/www/quiz>
    AllowOverride All
    Require all granted
</Directory>
```

Ou, de forma mais segura, **bloqueia explicitamente** `data/` diretamente no vhost (independentemente de `.htaccess`):

```apache
<Directory /var/www/quiz/data>
    Require all denied
</Directory>
```

Reinicia: `sudo systemctl reload apache2`.

### 5.4. Testa

Abre `http://o-teu-servidor/quiz/`. Deverias ver o ecrã inicial. Verifica depois que `http://o-teu-servidor/quiz/data/.config.php` **não** é acessível (deve devolver 403 ou conteúdo vazio).

---

## 6. Instalação com Nginx + PHP-FPM

O Nginx **não** lê `.htaccess`, por isso a proteção da pasta `data/` tem de ser configurada manualmente — **este passo é obrigatório**.

### 6.1. Copia o ficheiro e define as permissões

```bash
sudo mkdir -p /var/www/quiz
sudo cp index.php /var/www/quiz/
sudo chown -R www-data:www-data /var/www/quiz
sudo chmod 755 /var/www/quiz
```

### 6.2. Configura o site

Exemplo de bloco de servidor (ajusta o caminho do socket PHP-FPM à tua versão):

```nginx
server {
    listen 80;
    server_name exemplo.com;
    root /var/www/quiz;
    index index.php;

    # BLOQUEIA o acesso web à pasta de dados (OBRIGATÓRIO)
    location ^~ /data/ {
        deny all;
        return 403;
    }

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

### 6.3. Testa e reinicia

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Abre `http://exemplo.com/` e verifica depois que `http://exemplo.com/data/.config.php` devolve **403**.

---

## 7. Instalação rápida para teste

Para ensaios locais (não para produção):

```bash
cd /caminho/para/a-pasta-com-index.php
php -S 0.0.0.0:8080
```

Depois abre `http://localhost:8080/`. O servidor integrado executa PHP e cria `data/` na pasta atual.

> ⚠️ O servidor `php -S` é **apenas para desenvolvimento**. Não o exponhas na internet e não o uses para um workshop real — não tem as proteções e a robustez de um servidor web verdadeiro.

---

## 8. Primeira configuração: a palavra-passe de anfitrião

No primeiro uso não há nenhuma palavra-passe definida. A primeira pessoa que quer **criar** jogos tem de definir a palavra-passe de anfitrião:

1. Abre o endereço da aplicação.
2. Entra na zona de anfitrião / autenticação.
3. Como ainda não há palavra-passe, é-te pedido que **definas** uma. Introduz uma palavra-passe de **no mínimo 6 caracteres** (recomendada muito mais longa e única).
4. A palavra-passe é submetida a **hash** (`password_hash`, o algoritmo predefinido do PHP) e guardada em `data/.config.php`. O texto da palavra-passe **não** é armazenado em lado nenhum.
5. Depois de a definir, ficas automaticamente autenticado como anfitrião.

**Autenticações posteriores:** introduzes a palavra-passe; a aplicação verifica-a com `password_verify`. Em caso de sucesso, a tua sessão recebe direitos de anfitrião e um token CSRF. Há um pequeno atraso em caso de palavra-passe errada, para desencorajar a adivinhação por força bruta.

**Terminar sessão:** o botão de terminar sessão limpa os direitos de anfitrião da sessão.

> A palavra-passe protege a **criação e o controlo** dos jogos/atividades. Os participantes não precisam dela. Escolhe uma palavra-passe que possas partilhar em segurança com os coorganizadores, se for o caso.

---

## 9. Estrutura dos ficheiros e dos dados

Tudo fica ao lado de `index.php`, na pasta `data/` (autocriada):

```
index.php                  ← a aplicação (ficheiro único)
data/                      ← pasta de dados (autocriada, NÃO PÚBLICA)
├── .config.php            ← configuração: hash palavra-passe anfitrião, indicador moderação (SENSÍVEL)
├── .htaccess              ← bloqueia o acesso web (só Apache)
├── index.html             ← marcador 403, anti-listagem
├── feedback.txt           ← mensagens do «livro de visitas» (uma linha/registo)
├── quizzes/               ← questionários armazenados no servidor (se for o caso)
└── live/                  ← sessões ao vivo: um ficheiro JSON por jogo/atividade
    ├── ABCD.json
    ├── EFGH.json
    └── ...
```

Pontos importantes:

- **`data/.config.php`** contém o hash da palavra-passe — trata-o como um segredo. Sendo um ficheiro `.php`, mesmo que acedido via web, devolve conteúdo vazio (executa-se, não se mostra). Ainda assim, protege a pasta `data/` (ver segurança).
- **`data/live/*.json`** são as sessões de jogo. **Acumulam-se** — cada jogo ao vivo ou atividade cria um ficheiro. Têm de ser **limpas periodicamente** (secção 12).
- **As escritas são atómicas** (ficheiro temporário + renomeação) e **serializadas** (bloqueio com `flock`), por isso não se corrompem com acessos simultâneos.
- Os códigos de sessão são curtos (4 caracteres de `A–Z0–9`, sem caracteres ambíguos) e rigorosamente validados — não podem conter caminhos de ficheiro.

---

## 10. Segurança

A aplicação foi concebida de forma defensiva, mas **algumas medidas dependem de ti, o administrador.**

### O que a aplicação faz sozinha

- **Escape consistente** dos dados dos utilizadores na apresentação (proteção XSS).
- **Sanitização rigorosa** dos códigos/IDs usados em caminhos de ficheiro (sem path traversal).
- **CSRF**: as ações de anfitrião (criação/controlo) exigem um token CSRF verificado com `hash_equals`.
- **Regeneração do ID de sessão** na autenticação (anti fixação de sessão).
- **Limitação do tamanho dos pedidos** (rejeita payloads demasiado grandes) e **limite máximo** das entradas (número de perguntas, comprimentos de texto, valores de avaliação, etc.).
- **Limitação de taxa** (rate-limiting) nos envios e nas reações.

### O que TU tens de fazer

1. **Bloqueia o acesso web a `data/`** — obrigatório no Nginx (secção 6), verificado no Apache (secção 5). Confirma manualmente que `.../data/.config.php` devolve 403.
2. **Ativa HTTPS** (secção 11). Sem ele, a palavra-passe de anfitrião e o cookie de sessão circulam em claro.
3. **Reforça o cookie de sessão.** A aplicação usa as definições predefinidas do PHP. Recomendado, em `php.ini` (ou na configuração do pool PHP-FPM):

   ```ini
   session.cookie_httponly = 1
   session.cookie_secure   = 1   ; requer HTTPS
   session.cookie_samesite = Lax
   session.use_strict_mode = 1
   ```

4. **Adiciona cabeçalhos de segurança** ao nível do servidor web (a aplicação não os define sozinha). Exemplo mínimo (Nginx):

   ```nginx
   add_header X-Content-Type-Options "nosniff" always;
   add_header X-Frame-Options "SAMEORIGIN" always;
   add_header Referrer-Policy "strict-origin-when-cross-origin" always;
   # Uma política CSP adequada requer testes, porque a interface usa estilos/scripts inline.
   ```

   > Nota: a interface usa JS e CSS **inline**, por isso uma CSP rigorosa com `script-src` restritivo irá bloqueá-la. Se quiseres uma CSP, testa com cuidado (possivelmente `'unsafe-inline'` para começar, depois refina).

5. **Restringe o acesso se necessário.** Se o servidor for apenas para um workshop interno, podes limitar o acesso a uma rede/VPN ou adicionar autenticação ao nível do servidor.

### Limitação conhecida: o filtro de linguagem

O filtro opcional de linguagem grosseira (nas atividades públicas) é **básico** e fácil de contornar (escrita «l33t», espaçamento, homóglifos). Trata-o como uma ajuda cosmética, **não** como uma moderação fiável. Para um controlo real do conteúdo público, usa a **moderação de perguntas e respostas** (aprovação antes de mostrar) e a supervisão humana.

---

## 11. HTTPS e PWA

- **HTTPS** é necessário para: a instalação como aplicação (PWA), `session.cookie_secure`, e a proteção da palavra-passe de anfitrião.
- A via mais simples em Ubuntu: **Let's Encrypt** com Certbot.

  ```bash
  sudo apt install certbot python3-certbot-nginx   # ou -apache
  sudo certbot --nginx -d exemplo.com              # ou --apache
  ```

- **PWA**: a aplicação serve sozinha o manifesto e o service worker (através de parâmetros internos do tipo `?asset=...`). Uma vez em HTTPS, os utilizadores podem instalar a aplicação a partir do navegador. Não é preciso configuração adicional.

---

## 12. Manutenção

### Limpeza das sessões antigas (recomendado)

Os ficheiros em `data/live/` acumulam-se. Elimina periodicamente os antigos. Exemplo de tarefa cron que elimina as sessões não modificadas há mais de **24 horas**:

```bash
# edita a crontab do utilizador do servidor web
sudo -u www-data crontab -e
```

Adiciona uma linha (ajusta o caminho):

```cron
0 3 * * * find /var/www/quiz/data/live -name '*.json' -mmin +1440 -delete
```

Isto é executado diariamente às 03:00 e elimina as sessões mais antigas do que 1440 minutos (24 h). Ajusta o limite às tuas necessidades (por ex. `-mmin +720` para 12 h).

> Não elimines `data/.config.php` (a palavra-passe) e `data/feedback.txt` a não ser intencionalmente.

### Monitorização do espaço em disco

```bash
du -sh /var/www/quiz/data
ls -1 /var/www/quiz/data/live | wc -l   # quantas sessões ativas/residuais
```

### Verificação das permissões

Se a aplicação não conseguir escrever (os jogos não se criam), verifica:

```bash
ls -ld /var/www/quiz /var/www/quiz/data
sudo chown -R www-data:www-data /var/www/quiz/data
```

---

## 13. Cópia de segurança e restauro

O que conta para a cópia de segurança: **`data/.config.php`** (a palavra-passe) e, se usares o armazenamento no servidor dos questionários, **`data/quizzes/`**. As sessões ao vivo (`data/live/`) são geralmente efémeras e não necessitam de cópia de segurança.

### Cópia de segurança

```bash
sudo tar czf quiz-backup-$(date +%F).tar.gz -C /var/www/quiz data
```

Guarda o arquivo num local seguro (contém o hash da palavra-passe).

### Restauro

```bash
sudo tar xzf quiz-backup-2026-07-01.tar.gz -C /var/www/quiz
sudo chown -R www-data:www-data /var/www/quiz/data
```

---

## 14. Atualização da aplicação

Sendo um único ficheiro, a atualização é simples:

1. **Faz a cópia de segurança** de `data/` (secção 13) e guarda uma cópia do antigo `index.php`.
2. Substitui `index.php` pela nova versão.
3. Repara o proprietário se necessário: `sudo chown www-data:www-data /var/www/quiz/index.php`.
4. Recarrega a página e **testa** um jogo de ensaio.

Os dados em `data/` (incluindo a palavra-passe) **são conservados** durante a atualização, porque estão separados de `index.php`. O formato dos questionários é estável entre versões, por isso os questionários existentes mantêm-se compatíveis.

---

## 15. Repor a palavra-passe de anfitrião

Se esqueceste a palavra-passe:

1. Faz a cópia de segurança de `data/`.
2. **Elimina** (ou renomeia) o ficheiro de configuração:

   ```bash
   sudo mv /var/www/quiz/data/.config.php /var/www/quiz/data/.config.php.bak
   ```

3. Recarrega a aplicação. Como já não há palavra-passe definida, poderás **definir uma nova** tal como na primeira configuração (secção 8).

> Isto apenas repõe a palavra-passe; **não** afeta os questionários nem as sessões. O antigo ficheiro `.config.php.bak` contém apenas o hash antigo — elimina-o depois de te certificares de que está tudo em ordem.

---

## 16. Resolução de problemas

**A página está em branco / erro 500.**
Verifica o registo de erros do PHP/do servidor:
- Apache: `sudo tail -f /var/log/apache2/error.log`
- Nginx + PHP-FPM: `sudo tail -f /var/log/nginx/error.log` e `/var/log/php8.3-fpm.log`
Causas frequentes: versão do PHP demasiado antiga (<8.0), permissões erradas na pasta.

**Os jogos/atividades não se criam («write failed»).**
A pasta `data/` (ou `data/live/`) não tem permissão de escrita. Repara o proprietário/as permissões:
```bash
sudo chown -R www-data:www-data /var/www/quiz/data
sudo chmod -R u+rwX /var/www/quiz/data
```

**Os ficheiros em `data/` são acessíveis a partir do navegador.**
Não bloqueaste a pasta. No Nginx adiciona o bloco `location ^~ /data/ { deny all; }` (secção 6); no Apache verifica `AllowOverride All` ou bloqueia no vhost (secção 5). Confirma com um pedido a `.../data/.config.php` (deve ser 403).

**«bad csrf» nas ações de anfitrião.**
A sessão expirou ou os cookies estão bloqueados. Reautentica-te como anfitrião. Verifica também que os cookies de sessão funcionam (domínio/HTTPS correto).

**A instalação como aplicação (PWA) não aparece.**
O PWA requer HTTPS. Configura um certificado (secção 11).

**Os diacríticos/a correspondência na resposta livre parecem estranhos.**
A aplicação tem uma reserva interna para os diacríticos romenos mesmo sem a extensão `intl`. Se ainda assim vires problemas, instalar as extensões `php-intl` e `php-mbstring` pode ajudar:
```bash
sudo apt install php8.3-intl php8.3-mbstring && sudo systemctl reload php8.3-fpm
```

**As sessões PHP não persistem (desconecta-te frequentemente).**
Verifica as definições `session.save_path` em `php.ini` e que essa pasta tem permissão de escrita; verifica o relógio do servidor e a configuração dos cookies.

---

## 17. Desempenho e limites

- **Sem base de dados**, cada resposta num jogo ao vivo implica uma leitura-modificação-escrita com bloqueio do ficheiro de sessão. Para dimensões típicas de workshop (dezenas de participantes) é perfeitamente adequado.
- **Para grupos muito grandes** (muitas centenas de participantes em simultâneo na mesma sessão), a contenção sobre o ficheiro de sessão pode aumentar a latência. A aplicação foi pensada para workshops, não para eventos de massa do tipo estádio.
- **Limites predefinidos** (por motivos de robustez): um máximo de **100 perguntas** por questionário, comprimentos máximos para o título/as perguntas/as respostas/os nomes/as mensagens, tamanho máximo do pedido. Estes limites protegem o servidor do abuso e de entradas acidentalmente enormes.
- **Limpa `data/live/`** periodicamente (secção 12) para evitar a acumulação de ficheiros.

---

## 18. Lista de verificação antes de um workshop

- [ ] `index.php` está no servidor e a página inicial carrega.
- [ ] O acesso web a `data/` está **bloqueado** (verificado com um pedido a `.../data/.config.php`).
- [ ] **HTTPS** ativo (necessário para o PWA e a segurança).
- [ ] A palavra-passe de anfitrião está **definida** e conhece-la.
- [ ] Fizeste um **jogo ao vivo de ensaio de ponta a ponta** com 2–3 dispositivos reais, na rede que vais usar, testando **cada tipo de pergunta** (escolha múltipla, verdadeiro-falso, resposta livre, numérica) e, se as usares, as **equipas** e as **atividades de audiência**.
- [ ] Testaste o **código de entrada** e o **QR** a partir de um telemóvel na sala.
- [ ] Tens um plano de reserva (por ex. a edição offline HTML) em caso de problemas de rede.
- [ ] A tarefa de **limpeza** das sessões está configurada (cron) ou sabes fazê-la manualmente.
- [ ] Tens uma **cópia de segurança** recente de `data/`.

---

## 19. Uma nota honesta sobre os testes

Esta aplicação foi desenvolvida e verificada intensamente ao nível da **lógica** (fórmulas de pontuação, correspondência de texto, agregação, validações), mas o **fluxo integrado de ponta a ponta num servidor real, no navegador, com vários dispositivos** é da tua responsabilidade validar antes de te fiares nele publicamente. Em particular:

- **Executa um jogo ao vivo completo** na tua infraestrutura real antes de um workshop — é aqui que surgem eventuais problemas de integração que não aparecem nos testes por componentes.
- **Testa as combinações** que vais usar efetivamente (por ex. apresentação com perguntas e respostas moderadas + filtro, equipas + perguntas numéricas, reentrada offline no PWA).
- **Verifica os cabeçalhos e os cookies de sessão** se expuseres a aplicação na internet, não apenas numa rede local de workshop.
- Lembra-te de que o **filtro de linguagem é fraco** — fia-te na moderação de perguntas e respostas e na supervisão humana para o conteúdo público.

Trata-a como uma ferramenta sólida, mas **testa-a tu de ponta a ponta** nas tuas condições antes de a pores diante de um público.

---

*Undava — a edição servidor. Para a utilização propriamente dita (criar questionários, jogar, atividades), ver o **Manual do utilizador**.*
</script>
<script type="text/markdown" id="manual-admin-de">
# Undava — Administratorhandbuch

*Von der Installation bis zur Wartung. Für die Server-Edition (`index.php`).*

---

## Inhalt

1. [Architektur in Kürze](#1-architektur)
2. [Systemanforderungen](#2-systemanforderungen)
3. [Die Offline-Edition (HTML) — „Installation"](#3-offline-edition)
4. [Die Server-Edition installieren — Überblick](#4-server-installieren)
5. [Installation mit Apache](#5-apache)
6. [Installation mit Nginx + PHP-FPM](#6-nginx)
7. [Schnellinstallation zum Testen (`php -S`)](#7-schnelltest)
8. [Erste Konfiguration: das Gastgeber-Passwort](#8-gastgeber-passwort)
9. [Datei- und Datenstruktur](#9-dateistruktur)
10. [Sicherheit — Pflichtlektüre](#10-sicherheit)
11. [HTTPS und PWA](#11-https-pwa)
12. [Wartung](#12-wartung)
13. [Sicherung und Wiederherstellung](#13-sicherung-wiederherstellung)
14. [Aktualisierung der Anwendung](#14-aktualisierung)
15. [Das Gastgeber-Passwort zurücksetzen](#15-passwort-zuruecksetzen)
16. [Fehlerbehebung](#16-fehlerbehebung)
17. [Leistung und Grenzen](#17-leistung-grenzen)
18. [Checkliste vor einem Workshop](#18-checkliste)
19. [Eine ehrliche Anmerkung zum Stand der Tests](#19-anmerkung-tests)

---

## 1. Architektur in Kürze

- **Eine einzige Datei**: `index.php`. Sie enthält den Server (PHP), die API und die Oberfläche (HTML/JS) zusammen.
- **Ohne Datenbank.** Speicherung in **flachen Dateien** (Flat-File) in einem Verzeichnis `data/`, das automatisch neben `index.php` erstellt wird.
- **Ohne externe Abhängigkeiten.** Sie braucht kein Composer, npm, keine Bibliotheken oder externe Dienste. Nur PHP.
- **Zustand**: die Spiel-/Aktivitätssitzungen sind JSON-Dateien in `data/live/`. Die Konfiguration (der Hash des Gastgeber-Passworts) ist in `data/.config.php`.
- **Authentifizierung**: eine einzige privilegierte Rolle — der **Gastgeber/Administrator** — geschützt mit einem Passwort (`password_hash`-Hash) und einem **CSRF**-Token pro Sitzung. Die Spielteilnehmer brauchen kein Konto.

---

## 2. Systemanforderungen

- **PHP 8.0 oder neuer** (getestet auf **PHP 8.3.6**). 8.1+ empfohlen.
  - Erforderliche Erweiterungen: die Standard-Erweiterungen (`json`, `session`, vorzugsweise `mbstring`). Es sind keine exotischen Erweiterungen nötig. `intl`/`Normalizer` sind **nicht** obligatorisch — die Anwendung hat eine Reserve für rumänische diakritische Zeichen.
- Ein **Webserver**, der PHP ausführt: Apache (mod_php oder PHP-FPM), Nginx + PHP-FPM, oder der integrierte `php -S`-Server (nur zum Testen).
- **Speicherplatz**: die Anwendung selbst ist ~350 KB. Die Daten wachsen mit der Anzahl der Sitzungen (jedes Spiel = eine JSON-Datei in der Größenordnung von Dutzenden bis Hunderten von KB).
- **Berechtigungen**: das Verzeichnis, in das du `index.php` legst, muss **vom Webserver-Benutzer beschreibbar** sein (damit es `data/` erstellen kann).
- **HTTPS**: dringend empfohlen (obligatorisch für PWA und zum Schutz des Passworts/des Sitzungscookies). Siehe Abschnitt 11.

---

## 3. Die Offline-Edition (HTML)

`quiz-fara-frontiere.html` erfordert keine Installation:

- Du kannst sie einfach verteilen (E-Mail, USB-Stick, Dateifreigabe) und jeder öffnet sie per Doppelklick.
- Optional kannst du sie auch von einem Webserver als statische Datei ausliefern, aber das ist nicht nötig.
- Sie erstellt keine Dateien auf dem Server; die Daten bleiben im Browser jedes Benutzers.

Der Rest dieses Handbuchs betrifft die **Server-Edition** (`index.php`).

---

## 4. Die Server-Edition installieren

Allgemeine Schritte (unten pro Server ausführlich):

1. Kopiere `index.php` in das gewünschte Verzeichnis im Webroot (die Site-Wurzel oder ein Unterverzeichnis, z. B. `/var/www/quiz/`).
2. Stelle sicher, dass der **Webserver-Benutzer** (`www-data` auf Debian/Ubuntu) in dieses Verzeichnis **schreiben** kann, damit die Anwendung `data/` selbst erstellen kann.
3. Konfiguriere den Webserver so, dass er PHP ausführt und **den Webzugriff auf `data/` blockiert**.
4. Öffne die Adresse in einem Browser und **lege das Gastgeber-Passwort fest** (Abschnitt 8).

> Das Verzeichnis `data/` (mit den Unterverzeichnissen `data/live/` und `data/quizzes/`, plus den Schutzdateien `.htaccess` und `index.html`) wird beim ersten Zugriff **automatisch** erstellt, wenn die Berechtigungen es erlauben.

---

## 5. Installation mit Apache

Wir gehen von Ubuntu/Debian mit Apache und PHP aus.

### 5.1. Datei kopieren und Berechtigungen setzen

```bash
sudo mkdir -p /var/www/quiz
sudo cp index.php /var/www/quiz/
sudo chown -R www-data:www-data /var/www/quiz
sudo chmod 755 /var/www/quiz
```

### 5.2. PHP-Ausführung sicherstellen

- Mit **mod_php**: prüfe `sudo a2enmod php8.3` (oder deine Version) und starte Apache neu.
- Mit **PHP-FPM**: `sudo a2enmod proxy_fcgi setenvif && sudo a2enconf php8.3-fpm`.

### 5.3. `.htaccess` erlauben (zum Schutz von `data/`)

Die Anwendung schreibt automatisch `data/.htaccess` mit `Require all denied`. Damit dies wirkt, muss dein vhost die Überschreibungen in diesem Verzeichnis erlauben. In der Site-Konfiguration (z. B. `/etc/apache2/sites-available/000-default.conf`):

```apache
<Directory /var/www/quiz>
    AllowOverride All
    Require all granted
</Directory>
```

Oder, sicherer, **blockiere `data/` explizit** direkt im vhost (unabhängig von `.htaccess`):

```apache
<Directory /var/www/quiz/data>
    Require all denied
</Directory>
```

Neu starten: `sudo systemctl reload apache2`.

### 5.4. Testen

Öffne `http://dein-server/quiz/`. Du solltest den Startbildschirm sehen. Prüfe dann, dass `http://dein-server/quiz/data/.config.php` **nicht** zugänglich ist (es muss 403 oder leeren Inhalt zurückgeben).

---

## 6. Installation mit Nginx + PHP-FPM

Nginx liest `.htaccess` **nicht**, daher muss der Schutz des Verzeichnisses `data/` manuell konfiguriert werden — **dieser Schritt ist obligatorisch**.

### 6.1. Datei kopieren und Berechtigungen setzen

```bash
sudo mkdir -p /var/www/quiz
sudo cp index.php /var/www/quiz/
sudo chown -R www-data:www-data /var/www/quiz
sudo chmod 755 /var/www/quiz
```

### 6.2. Die Site konfigurieren

Beispiel eines Server-Blocks (passe den Pfad des PHP-FPM-Sockets an deine Version an):

```nginx
server {
    listen 80;
    server_name beispiel.com;
    root /var/www/quiz;
    index index.php;

    # BLOCKIERE den Webzugriff auf das Datenverzeichnis (OBLIGATORISCH)
    location ^~ /data/ {
        deny all;
        return 403;
    }

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

### 6.3. Testen und neu starten

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Öffne `http://beispiel.com/` und prüfe dann, dass `http://beispiel.com/data/.config.php` **403** zurückgibt.

---

## 7. Schnellinstallation zum Testen

Für lokale Versuche (nicht für die Produktion):

```bash
cd /pfad/zum/ordner-mit-index.php
php -S 0.0.0.0:8080
```

Öffne dann `http://localhost:8080/`. Der integrierte Server führt PHP aus und erstellt `data/` im aktuellen Ordner.

> ⚠️ Der `php -S`-Server ist **nur für die Entwicklung**. Setze ihn nicht dem Internet aus und nutze ihn nicht für einen echten Workshop — er hat nicht die Schutzmechanismen und die Robustheit eines echten Webservers.

---

## 8. Erste Konfiguration: das Gastgeber-Passwort

Bei der ersten Nutzung ist kein Passwort festgelegt. Die erste Person, die Spiele **erstellen** will, muss das Gastgeber-Passwort festlegen:

1. Öffne die Adresse der Anwendung.
2. Betritt den Gastgeber- / Authentifizierungsbereich.
3. Da es noch kein Passwort gibt, wirst du aufgefordert, eines **festzulegen**. Gib ein Passwort mit **mindestens 6 Zeichen** ein (ein viel längeres und einzigartiges wird empfohlen).
4. Das Passwort wird **gehasht** (`password_hash`, der Standardalgorithmus von PHP) und in `data/.config.php` gespeichert. Der Passworttext wird **nirgends** gespeichert.
5. Nach dem Festlegen bist du automatisch als Gastgeber authentifiziert.

**Spätere Anmeldungen:** du gibst das Passwort ein; die Anwendung prüft es mit `password_verify`. Bei Erfolg erhält deine Sitzung Gastgeberrechte und einen CSRF-Token. Bei einem falschen Passwort gibt es eine kleine Verzögerung, um das Erraten per Brute Force zu erschweren.

**Abmelden:** die Abmeldeschaltfläche löscht die Gastgeberrechte aus der Sitzung.

> Das Passwort schützt die **Erstellung und Steuerung** der Spiele/Aktivitäten. Die Teilnehmer brauchen es nicht. Wähle ein Passwort, das du gegebenenfalls sicher mit den Mitorganisatoren teilen kannst.

---

## 9. Datei- und Datenstruktur

Alles befindet sich neben `index.php`, im Verzeichnis `data/` (automatisch erstellt):

```
index.php                  ← die Anwendung (einzelne Datei)
data/                      ← Datenverzeichnis (automatisch erstellt, NICHT ÖFFENTLICH)
├── .config.php            ← Konfiguration: Hash Gastgeber-Passwort, Moderations-Flag (SENSIBEL)
├── .htaccess              ← blockiert den Webzugriff (nur Apache)
├── index.html             ← 403-Platzhalter, Anti-Auflistung
├── feedback.txt           ← Nachrichten aus dem „Gästebuch" (eine Zeile/Eintrag)
├── quizzes/               ← auf dem Server gespeicherte Quiz (falls zutreffend)
└── live/                  ← Live-Sitzungen: eine JSON-Datei pro Spiel/Aktivität
    ├── ABCD.json
    ├── EFGH.json
    └── ...
```

Wichtige Punkte:

- **`data/.config.php`** enthält den Passwort-Hash — behandle ihn als Geheimnis. Da es eine `.php`-Datei ist, gibt sie, selbst wenn sie über das Web aufgerufen wird, leeren Inhalt zurück (sie wird ausgeführt, nicht angezeigt). Schütze dennoch das Verzeichnis `data/` (siehe Sicherheit).
- **`data/live/*.json`** sind die Spielsitzungen. Sie **sammeln sich an** — jedes Live-Spiel oder jede Aktivität erstellt eine Datei. Sie müssen **regelmäßig bereinigt** werden (Abschnitt 12).
- **Die Schreibvorgänge sind atomar** (temporäre Datei + Umbenennung) und **serialisiert** (Sperrung mit `flock`), sodass sie bei gleichzeitigem Zugriff nicht beschädigt werden.
- Die Sitzungscodes sind kurz (4 Zeichen aus `A–Z0–9`, ohne mehrdeutige Zeichen) und streng validiert — sie können keine Dateipfade enthalten.

---

## 10. Sicherheit

Die Anwendung ist defensiv konzipiert, aber **einige Maßnahmen hängen von dir, dem Administrator, ab.**

### Was die Anwendung von selbst tut

- **Konsistentes Escaping** der Benutzerdaten bei der Anzeige (XSS-Schutz).
- **Strenge Bereinigung** der in Dateipfaden verwendeten Codes/IDs (kein Path Traversal).
- **CSRF**: Gastgeberaktionen (Erstellung/Steuerung) erfordern einen mit `hash_equals` überprüften CSRF-Token.
- **Regenerierung der Sitzungs-ID** bei der Authentifizierung (Anti-Session-Fixation).
- **Begrenzung der Anfragegröße** (lehnt zu große Payloads ab) und **Begrenzung** der Eingaben (Anzahl der Fragen, Textlängen, Bewertungswerte usw.).
- **Ratenbegrenzung** (Rate-Limiting) bei Einreichungen und Reaktionen.

### Was DU tun musst

1. **Blockiere den Webzugriff auf `data/`** — obligatorisch bei Nginx (Abschnitt 6), verifiziert bei Apache (Abschnitt 5). Bestätige manuell, dass `.../data/.config.php` 403 zurückgibt.
2. **Aktiviere HTTPS** (Abschnitt 11). Ohne es zirkulieren das Gastgeber-Passwort und das Sitzungscookie im Klartext.
3. **Härte das Sitzungscookie.** Die Anwendung nutzt die Standardeinstellungen von PHP. Empfohlen, in `php.ini` (oder in der Konfiguration des PHP-FPM-Pools):

   ```ini
   session.cookie_httponly = 1
   session.cookie_secure   = 1   ; erfordert HTTPS
   session.cookie_samesite = Lax
   session.use_strict_mode = 1
   ```

4. **Füge Sicherheits-Header** auf Webserver-Ebene hinzu (die Anwendung setzt sie nicht selbst). Minimales Beispiel (Nginx):

   ```nginx
   add_header X-Content-Type-Options "nosniff" always;
   add_header X-Frame-Options "SAMEORIGIN" always;
   add_header Referrer-Policy "strict-origin-when-cross-origin" always;
   # Eine geeignete CSP-Richtlinie erfordert Tests, da die Oberfläche Inline-Stile/-Skripte nutzt.
   ```

   > Hinweis: die Oberfläche nutzt **Inline**-JS und -CSS, daher wird eine strenge CSP mit restriktivem `script-src` sie blockieren. Wenn du eine CSP willst, teste sorgfältig (möglicherweise `'unsafe-inline'` zu Beginn, dann verfeinern).

5. **Schränke den Zugriff bei Bedarf ein.** Wenn der Server nur für einen internen Workshop ist, kannst du den Zugriff auf ein Netzwerk/VPN beschränken oder eine Authentifizierung auf Serverebene hinzufügen.

### Bekannte Einschränkung: der Sprachfilter

Der optionale Filter für vulgäre Sprache (bei öffentlichen Aktivitäten) ist **grundlegend** und leicht zu umgehen („l33t"-Schreibweise, Abstände, Homoglyphen). Behandle ihn als kosmetische Hilfe, **nicht** als zuverlässige Moderation. Für eine echte Kontrolle des öffentlichen Inhalts nutze die **Q&A-Moderation** (Genehmigung vor der Anzeige) und menschliche Aufsicht.

---

## 11. HTTPS und PWA

- **HTTPS** ist erforderlich für: die Installation als App (PWA), `session.cookie_secure`, und den Schutz des Gastgeber-Passworts.
- Der einfachste Weg auf Ubuntu: **Let's Encrypt** mit Certbot.

  ```bash
  sudo apt install certbot python3-certbot-nginx   # oder -apache
  sudo certbot --nginx -d beispiel.com             # oder --apache
  ```

- **PWA**: die Anwendung liefert das Manifest und den Service Worker selbst aus (über interne Parameter des Typs `?asset=...`). Einmal auf HTTPS, können die Benutzer die Anwendung aus dem Browser installieren. Keine zusätzliche Konfiguration nötig.

---

## 12. Wartung

### Bereinigung alter Sitzungen (empfohlen)

Die Dateien in `data/live/` sammeln sich an. Lösche die alten regelmäßig. Beispiel einer Cron-Aufgabe, die Sitzungen löscht, die seit über **24 Stunden** unverändert sind:

```bash
# bearbeite die Crontab des Webserver-Benutzers
sudo -u www-data crontab -e
```

Füge eine Zeile hinzu (passe den Pfad an):

```cron
0 3 * * * find /var/www/quiz/data/live -name '*.json' -mmin +1440 -delete
```

Dies läuft täglich um 03:00 und löscht Sitzungen, die älter als 1440 Minuten (24 h) sind. Passe die Schwelle an deine Bedürfnisse an (z. B. `-mmin +720` für 12 h).

> Lösche `data/.config.php` (das Passwort) und `data/feedback.txt` nicht, außer absichtlich.

### Überwachung des Speicherplatzes

```bash
du -sh /var/www/quiz/data
ls -1 /var/www/quiz/data/live | wc -l   # wie viele aktive/übrige Sitzungen
```

### Überprüfung der Berechtigungen

Wenn die Anwendung nicht schreiben kann (Spiele werden nicht erstellt), prüfe:

```bash
ls -ld /var/www/quiz /var/www/quiz/data
sudo chown -R www-data:www-data /var/www/quiz/data
```

---

## 13. Sicherung und Wiederherstellung

Was für die Sicherung zählt: **`data/.config.php`** (das Passwort) und, wenn du die Serverspeicherung der Quiz nutzt, **`data/quizzes/`**. Live-Sitzungen (`data/live/`) sind meist flüchtig und brauchen keine Sicherung.

### Sicherung

```bash
sudo tar czf quiz-backup-$(date +%F).tar.gz -C /var/www/quiz data
```

Bewahre das Archiv an einem sicheren Ort auf (es enthält den Passwort-Hash).

### Wiederherstellung

```bash
sudo tar xzf quiz-backup-2026-07-01.tar.gz -C /var/www/quiz
sudo chown -R www-data:www-data /var/www/quiz/data
```

---

## 14. Aktualisierung der Anwendung

Da es eine einzige Datei ist, ist die Aktualisierung einfach:

1. **Sichere** `data/` (Abschnitt 13) und bewahre eine Kopie des alten `index.php` auf.
2. Ersetze `index.php` durch die neue Version.
3. Repariere den Eigentümer bei Bedarf: `sudo chown www-data:www-data /var/www/quiz/index.php`.
4. Lade die Seite neu und **teste** ein Probespiel.

Die Daten in `data/` (einschließlich des Passworts) **bleiben** bei der Aktualisierung erhalten, weil sie von `index.php` getrennt sind. Das Quiz-Format ist zwischen den Versionen stabil, sodass bestehende Quiz kompatibel bleiben.

---

## 15. Das Gastgeber-Passwort zurücksetzen

Wenn du das Passwort vergessen hast:

1. Sichere `data/`.
2. **Lösche** (oder benenne um) die Konfigurationsdatei:

   ```bash
   sudo mv /var/www/quiz/data/.config.php /var/www/quiz/data/.config.php.bak
   ```

3. Lade die Anwendung neu. Da kein Passwort mehr festgelegt ist, kannst du **ein neues festlegen**, genau wie bei der ersten Konfiguration (Abschnitt 8).

> Dies setzt nur das Passwort zurück; es **beeinträchtigt** die Quiz oder Sitzungen **nicht**. Die alte Datei `.config.php.bak` enthält nur den alten Hash — lösche sie, nachdem du dich vergewissert hast, dass alles in Ordnung ist.

---

## 16. Fehlerbehebung

**Die Seite ist leer / Fehler 500.**
Prüfe das PHP-/Server-Fehlerprotokoll:
- Apache: `sudo tail -f /var/log/apache2/error.log`
- Nginx + PHP-FPM: `sudo tail -f /var/log/nginx/error.log` und `/var/log/php8.3-fpm.log`
Häufige Ursachen: zu alte PHP-Version (<8.0), falsche Verzeichnisberechtigungen.

**Die Spiele/Aktivitäten werden nicht erstellt („write failed").**
Das Verzeichnis `data/` (oder `data/live/`) ist nicht beschreibbar. Repariere den Eigentümer/die Berechtigungen:
```bash
sudo chown -R www-data:www-data /var/www/quiz/data
sudo chmod -R u+rwX /var/www/quiz/data
```

**Die Dateien in `data/` sind aus dem Browser zugänglich.**
Du hast das Verzeichnis nicht blockiert. Bei Nginx füge den Block `location ^~ /data/ { deny all; }` hinzu (Abschnitt 6); bei Apache prüfe `AllowOverride All` oder blockiere im vhost (Abschnitt 5). Bestätige mit einer Anfrage an `.../data/.config.php` (muss 403 sein).

**„bad csrf" bei Gastgeberaktionen.**
Die Sitzung ist abgelaufen oder Cookies sind blockiert. Melde dich erneut als Gastgeber an. Prüfe auch, dass die Sitzungscookies funktionieren (korrekte Domain/HTTPS).

**Die Installation als App (PWA) erscheint nicht.**
Das PWA erfordert HTTPS. Konfiguriere ein Zertifikat (Abschnitt 11).

**Die diakritischen Zeichen/die Übereinstimmung bei der freien Antwort erscheinen seltsam.**
Die Anwendung hat eine interne Reserve für rumänische diakritische Zeichen auch ohne die `intl`-Erweiterung. Wenn du dennoch Probleme siehst, kann die Installation der Erweiterungen `php-intl` und `php-mbstring` helfen:
```bash
sudo apt install php8.3-intl php8.3-mbstring && sudo systemctl reload php8.3-fpm
```

**Die PHP-Sitzungen bleiben nicht bestehen (es meldet dich oft ab).**
Prüfe die `session.save_path`-Einstellungen in `php.ini` und dass dieses Verzeichnis beschreibbar ist; prüfe die Serveruhr und die Cookie-Konfiguration.

---

## 17. Leistung und Grenzen

- **Ohne Datenbank** bedeutet jede Antwort in einem Live-Spiel ein Lesen-Ändern-Schreiben mit Sperrung der Sitzungsdatei. Für typische Workshop-Größen (Dutzende Teilnehmer) ist es vollkommen angemessen.
- **Bei sehr großen Gruppen** (viele Hunderte Teilnehmer gleichzeitig in derselben Sitzung) kann die Konkurrenz um die Sitzungsdatei die Latenz erhöhen. Die Anwendung ist für Workshops gedacht, nicht für Massenveranstaltungen vom Typ Stadion.
- **Standardgrenzen** (aus Robustheitsgründen): maximal **100 Fragen** pro Quiz, maximale Längen für Titel/Fragen/Antworten/Namen/Nachrichten, maximale Anfragegröße. Diese Grenzen schützen den Server vor Missbrauch und vor versehentlich riesigen Eingaben.
- **Bereinige `data/live/`** regelmäßig (Abschnitt 12), um die Ansammlung von Dateien zu vermeiden.

---

## 18. Checkliste vor einem Workshop

- [ ] `index.php` ist auf dem Server und die Startseite lädt.
- [ ] Der Webzugriff auf `data/` ist **blockiert** (verifiziert mit einer Anfrage an `.../data/.config.php`).
- [ ] **HTTPS** aktiv (erforderlich für PWA und Sicherheit).
- [ ] Das Gastgeber-Passwort ist **festgelegt** und du kennst es.
- [ ] Du hast ein **vollständiges Ende-zu-Ende-Probespiel** mit 2–3 echten Geräten durchgeführt, im Netzwerk, das du nutzen wirst, und dabei **jeden Fragetyp** getestet (Multiple Choice, Wahr-Falsch, freie Antwort, numerisch) und, falls du sie nutzt, die **Teams** und die **Publikumsaktivitäten**.
- [ ] Du hast den **Beitrittscode** und den **QR** von einem Handy im Raum getestet.
- [ ] Du hast einen Notfallplan (z. B. die Offline-HTML-Edition) für den Fall von Netzwerkproblemen.
- [ ] Die Aufgabe zur **Bereinigung** der Sitzungen ist konfiguriert (Cron) oder du weißt, wie man sie manuell durchführt.
- [ ] Du hast eine aktuelle **Sicherung** von `data/`.

---

## 19. Eine ehrliche Anmerkung zu den Tests

Diese Anwendung wurde auf der Ebene der **Logik** entwickelt und intensiv verifiziert (Bewertungsformeln, Textabgleich, Aggregation, Validierungen), aber der **integrierte Ende-zu-Ende-Ablauf auf einem echten Server, im Browser, mit mehreren Geräten** liegt in deiner Verantwortung zu validieren, bevor du dich öffentlich darauf verlässt. Insbesondere:

- **Führe ein vollständiges Live-Spiel** auf deiner echten Infrastruktur vor einem Workshop durch — hier treten etwaige Integrationsprobleme zutage, die bei Komponententests nicht auftauchen.
- **Teste die Kombinationen**, die du tatsächlich nutzen wirst (z. B. Präsentation mit moderiertem Q&A + Filter, Teams + numerische Fragen, Offline-Wiedereintritt in das PWA).
- **Prüfe die Sitzungs-Header und -Cookies**, wenn du die Anwendung dem Internet aussetzt, nicht nur in einem lokalen Workshop-Netzwerk.
- Denke daran, dass der **Sprachfilter schwach ist** — verlasse dich auf die Q&A-Moderation und menschliche Aufsicht für öffentliche Inhalte.

Behandle sie als solides Werkzeug, aber **teste sie selbst Ende-zu-Ende** unter deinen Bedingungen, bevor du sie vor ein Publikum stellst.

---

*Undava — die Server-Edition. Für die eigentliche Nutzung (Quiz erstellen, spielen, Aktivitäten) siehe das **Benutzerhandbuch**.*
</script>
<script type="text/markdown" id="manual-admin">
# Undava — Manual al administratorului

*De la instalare la întreținere. Pentru ediția server (`index.php`).*

---

## Cuprins

1. [Arhitectura pe scurt](#1-arhitectura-pe-scurt)
2. [Cerințe de sistem](#2-cerințe-de-sistem)
3. [Ediția offline (HTML) — „instalare"](#3-ediția-offline-html)
4. [Instalarea ediției server — prezentare](#4-instalarea-ediției-server)
5. [Instalare cu Apache](#5-instalare-cu-apache)
6. [Instalare cu Nginx + PHP-FPM](#6-instalare-cu-nginx--php-fpm)
7. [Instalare rapidă pentru test (`php -S`)](#7-instalare-rapidă-pentru-test)
8. [Prima configurare: parola de gazdă](#8-prima-configurare-parola-de-gazdă)
9. [Structura fișierelor și a datelor](#9-structura-fișierelor-și-a-datelor)
10. [Securitate — obligatoriu de citit](#10-securitate)
11. [HTTPS și PWA](#11-https-și-pwa)
12. [Întreținere (mentenanță)](#12-întreținere)
13. [Backup și restaurare](#13-backup-și-restaurare)
14. [Actualizarea aplicației](#14-actualizarea-aplicației)
15. [Resetarea parolei de gazdă](#15-resetarea-parolei-de-gazdă)
16. [Depanare](#16-depanare)
17. [Performanță și limite](#17-performanță-și-limite)
18. [Checklist înainte de un atelier](#18-checklist-înainte-de-un-atelier)
19. [Notă onestă despre stadiul testării](#19-notă-onestă-despre-testare)

---

## 1. Arhitectura pe scurt

- **Un singur fișier**: `index.php`. Conține serverul (PHP), API-ul și interfața (HTML/JS)
  la un loc.
- **Fără bază de date.** Stocare pe **fișiere plate** (flat-file) într-un director `data/`
  creat automat lângă `index.php`.
- **Fără dependențe externe.** Nu are nevoie de Composer, npm, biblioteci sau servicii
  externe. Doar PHP.
- **Stare**: sesiunile de joc/activități sunt fișiere JSON în `data/live/`. Configurarea
  (hash-ul parolei de gazdă) este în `data/.config.php`.
- **Autentificare**: o singură rolă privilegiată — **gazda/administratorul** — protejată cu
  parolă (hash `password_hash`) și token **CSRF** pe sesiune. Participanții la jocuri nu au
  nevoie de cont.

---

## 2. Cerințe de sistem

- **PHP 8.0 sau mai nou** (testat pe **PHP 8.3.6**). Recomandat 8.1+.
  - Extensii necesare: cele standard (`json`, `session`, `mbstring` de preferat). Nu sunt
    necesare extensii exotice. `intl`/`Normalizer` **nu** sunt obligatorii — aplicația are
    o rezervă pentru diacriticele românești.
- Un **server web** care execută PHP: Apache (mod_php sau PHP-FPM), Nginx + PHP-FPM, sau
  serverul încorporat `php -S` (doar pentru test).
- **Spațiu pe disc**: aplicația în sine e ~350 KB. Datele cresc cu numărul de sesiuni
  (fiecare joc = un fișier JSON de ordinul zecilor–sutelor de KB).
- **Permisiuni**: directorul în care pui `index.php` trebuie să fie **scriibil de către
  utilizatorul serverului web** (ca să poată crea `data/`).
- **HTTPS**: puternic recomandat (obligatoriu pentru PWA și pentru protejarea parolei/
  cookie-ului de sesiune). Vezi secțiunea 11.

---

## 3. Ediția offline (HTML)

`quiz-fara-frontiere.html` nu necesită instalare:

- Poți să-l distribui pur și simplu (email, stick, partajare de fișiere) și oricine îl
  deschide cu dublu-clic.
- Opțional, îl poți servi și de pe un web server ca fișier static, dar nu e necesar.
- Nu creează fișiere pe server; datele stau în browserul fiecărui utilizator.

Restul acestui manual se referă la **ediția server** (`index.php`).

---

## 4. Instalarea ediției server

Pași generali (detaliați pe fiecare server mai jos):

1. Copiază `index.php` în directorul dorit din rădăcina web (rădăcina site-ului sau un
   subdirector, de ex. `/var/www/quiz/`).
2. Asigură-te că **utilizatorul serverului web** (`www-data` pe Debian/Ubuntu) poate
   **scrie** în acel director, pentru ca aplicația să-și creeze singură `data/`.
3. Configurează serverul web să execute PHP și să **blocheze accesul web la `data/`**.
4. Deschide adresa în browser și **stabilește parola de gazdă** (secțiunea 8).

> Directorul `data/` (cu subdirectoarele `data/live/` și `data/quizzes/`, plus fișierele
> de protecție `.htaccess` și `index.html`) se creează **automat** la prima accesare, dacă
> permisiunile o permit.

---

## 5. Instalare cu Apache

Presupunem Ubuntu/Debian cu Apache și PHP.

### 5.1. Copiază fișierul și setează permisiunile

```bash
sudo mkdir -p /var/www/quiz
sudo cp index.php /var/www/quiz/
sudo chown -R www-data:www-data /var/www/quiz
sudo chmod 755 /var/www/quiz
```

### 5.2. Asigură execuția PHP

- Cu **mod_php**: verifică `sudo a2enmod php8.3` (sau versiunea ta) și repornește Apache.
- Cu **PHP-FPM**: `sudo a2enmod proxy_fcgi setenvif && sudo a2enconf php8.3-fpm`.

### 5.3. Permite `.htaccess` (pentru protecția lui `data/`)

Aplicația scrie automat `data/.htaccess` cu `Require all denied`. Ca acesta să aibă efect,
vhost-ul tău trebuie să permită suprascrierile în acel director. În configurarea site-ului
(de ex. `/etc/apache2/sites-available/000-default.conf`):

```apache
<Directory /var/www/quiz>
    AllowOverride All
    Require all granted
</Directory>
```

Sau, mai sigur, **blochează explicit** `data/` direct în vhost (independent de `.htaccess`):

```apache
<Directory /var/www/quiz/data>
    Require all denied
</Directory>
```

Repornește: `sudo systemctl reload apache2`.

### 5.4. Testează

Deschide `http://serverul-tău/quiz/`. Ar trebui să vezi ecranul principal. Verifică apoi că
`http://serverul-tău/quiz/data/.config.php` **nu** este accesibil (trebuie să dea 403 sau
conținut gol).

---

## 6. Instalare cu Nginx + PHP-FPM

Nginx **nu** citește `.htaccess`, deci protecția directorului `data/` trebuie configurată
manual — **acest pas este obligatoriu**.

### 6.1. Copiază fișierul și setează permisiunile

```bash
sudo mkdir -p /var/www/quiz
sudo cp index.php /var/www/quiz/
sudo chown -R www-data:www-data /var/www/quiz
sudo chmod 755 /var/www/quiz
```

### 6.2. Configurează site-ul

Exemplu de bloc de server (ajustează calea socket-ului PHP-FPM la versiunea ta):

```nginx
server {
    listen 80;
    server_name exemplu.ro;
    root /var/www/quiz;
    index index.php;

    # BLOCHEAZĂ accesul web la directorul de date (OBLIGATORIU)
    location ^~ /data/ {
        deny all;
        return 403;
    }

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

### 6.3. Testează și repornește

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Deschide `http://exemplu.ro/` și verifică apoi că `http://exemplu.ro/data/.config.php` dă
**403**.

---

## 7. Instalare rapidă pentru test

Pentru probe locale (nu pentru producție):

```bash
cd /calea/către/folderul-cu-index.php
php -S 0.0.0.0:8080
```

Apoi deschide `http://localhost:8080/`. Serverul încorporat rulează PHP și creează `data/`
în folderul curent.

> ⚠️ Serverul `php -S` este **doar pentru dezvoltare**. Nu îl expune pe internet și nu îl
> folosi pentru un atelier real — nu are protecțiile și robustețea unui server web
> adevărat.

---

## 8. Prima configurare: parola de gazdă

La prima folosire nu există nicio parolă setată. Prima persoană care vrea să **creeze**
jocuri trebuie să stabilească parola de gazdă:

1. Deschide adresa aplicației.
2. Intră în zona de gazdă / autentificare.
3. Deoarece nu există încă o parolă, ți se cere să **stabilești** una. Introdu o parolă de
   **minimum 6 caractere** (recomandat mult mai lungă și unică).
4. Parola este **hash-uită** (`password_hash`, algoritmul implicit al PHP) și salvată în
   `data/.config.php`. Textul parolei **nu** se stochează nicăieri.
5. După setare, ești autentificat automat ca gazdă.

**Autentificări ulterioare:** introduci parola; aplicația o verifică cu `password_verify`.
La succes, sesiunea ta primește drept de gazdă și un token CSRF. Există o mică întârziere
la parolă greșită, pentru a descuraja ghicirea prin forță brută.

**Delogare:** butonul de delogare curăță drepturile de gazdă din sesiune.

> Parola protejează **crearea și controlul** jocurilor/activităților. Participanții nu au
> nevoie de ea. Alege o parolă pe care o poți împărtăși în siguranță co-organizatorilor,
> dacă e cazul.

---

## 9. Structura fișierelor și a datelor

Totul stă lângă `index.php`, în directorul `data/` (auto-creat):

```
index.php                  ← aplicația (singur fișier)
data/                      ← director de date (auto-creat, NEPUBLIC)
├── .config.php            ← configurare: hash parolă gazdă, flag moderare (SENSIBIL)
├── .htaccess              ← blochează accesul web (doar Apache)
├── index.html             ← placeholder 403 anti-listare
├── feedback.txt           ← mesaje din „cartea de oaspeți" (o linie/înregistrare)
├── quizzes/               ← quiz-uri stocate pe server (dacă e cazul)
└── live/                  ← sesiuni live: câte un fișier JSON per joc/activitate
    ├── ABCD.json
    ├── EFGH.json
    └── ...
```

Puncte importante:

- **`data/.config.php`** conține hash-ul parolei — tratează-l ca secret. Fiind fișier
  `.php`, chiar dacă e accesat prin web, returnează conținut gol (se execută, nu se
  afișează). Totuși, protejează directorul `data/` (vezi securitate).
- **`data/live/*.json`** sunt sesiunile de joc. **Se acumulează** — fiecare joc live sau
  activitate creează un fișier. Trebuie **curățate periodic** (secțiunea 12).
- **Scrierile sunt atomice** (fișier temporar + redenumire) și **serializate** (blocare cu
  `flock`), deci nu se corup la accese simultane.
- Codurile de sesiune sunt scurte (4 caractere din `A–Z0–9`, fără caractere ambigue) și
  strict validate — nu pot conține căi de fișier.

---

## 10. Securitate

Aplicația este proiectată defensiv, dar **câteva măsuri depind de tine, administratorul.**

### Ce face aplicația singură

- **Escapare consecventă** a datelor de la utilizatori la afișare (protecție XSS).
- **Sanitizare strictă** a codurilor/ID-urilor folosite în căi de fișier (fără path
  traversal).
- **CSRF**: acțiunile de gazdă (creare/control) cer un token CSRF verificat cu
  `hash_equals`.
- **Regenerarea ID-ului de sesiune** la autentificare (anti-fixare de sesiune).
- **Limitarea dimensiunii cererilor** (respinge payload-uri prea mari) și **plafonarea**
  intrărilor (număr de întrebări, lungimi de text, valori de rating etc.).
- **Temporizare** (rate-limiting) pe trimiteri și pe reacții.

### Ce trebuie să faci TU

1. **Blochează accesul web la `data/`** — obligatoriu pe Nginx (secțiunea 6), verificat pe
   Apache (secțiunea 5). Confirmă manual că `.../data/.config.php` dă 403.
2. **Activează HTTPS** (secțiunea 11). Fără el, parola de gazdă și cookie-ul de sesiune
   circulă în clar.
3. **Întărește cookie-ul de sesiune.** Aplicația folosește setările implicite ale PHP.
   Recomandat, în `php.ini` (sau în configurarea pool-ului PHP-FPM):

   ```ini
   session.cookie_httponly = 1
   session.cookie_secure   = 1   ; necesită HTTPS
   session.cookie_samesite = Lax
   session.use_strict_mode = 1
   ```

4. **Adaugă anteturi de securitate** la nivel de server web (aplicația nu le setează
   singură). Exemplu minimal (Nginx):

   ```nginx
   add_header X-Content-Type-Options "nosniff" always;
   add_header X-Frame-Options "SAMEORIGIN" always;
   add_header Referrer-Policy "strict-origin-when-cross-origin" always;
   # O politică CSP potrivită necesită testare, fiindcă interfața folosește stiluri/scripturi inline.
   ```

   > Notă: interfața folosește JS și CSS **inline**, deci o CSP strictă cu `script-src`
   > restrictiv o va bloca. Dacă vrei CSP, testează cu atenție (posibil `'unsafe-inline'`
   > pentru început, apoi rafinează).

5. **Restrânge accesul dacă e nevoie.** Dacă serverul e doar pentru un atelier intern,
   poți limita accesul la o rețea/VPN sau adăuga autentificare la nivel de server.

### Limitare cunoscută: filtrul de limbaj

Filtrul opțional de limbaj vulgar (la activitățile publice) este **de bază** și ușor de
ocolit (scriere „l33t", spațiere, homoglife). Tratează-l ca un ajutor cosmetic, **nu** ca o
moderare fiabilă. Pentru control real al conținutului public, folosește **moderarea Q&A**
(aprobare înainte de afișare) și supraveghere umană.

---

## 11. HTTPS și PWA

- **HTTPS** este necesar pentru: instalarea ca aplicație (PWA), `session.cookie_secure`, și
  protejarea parolei de gazdă.
- Cea mai simplă cale pe Ubuntu: **Let's Encrypt** cu Certbot.

  ```bash
  sudo apt install certbot python3-certbot-nginx   # sau -apache
  sudo certbot --nginx -d exemplu.ro               # sau --apache
  ```

- **PWA**: aplicația servește singură manifestul și service worker-ul (prin parametri
  interni de tip `?asset=...`). Odată pe HTTPS, utilizatorii pot instala aplicația din
  browser. Nu e nevoie de configurare suplimentară.

---

## 12. Întreținere

### Curățarea sesiunilor vechi (recomandat)

Fișierele din `data/live/` se acumulează. Șterge-le periodic pe cele vechi. Exemplu de
sarcină cron care șterge sesiunile nemodificate de peste **24 de ore**:

```bash
# editează crontab-ul utilizatorului serverului web
sudo -u www-data crontab -e
```

Adaugă o linie (ajustează calea):

```cron
0 3 * * * find /var/www/quiz/data/live -name '*.json' -mmin +1440 -delete
```

Aceasta rulează zilnic la 03:00 și șterge sesiunile mai vechi de 1440 de minute (24 h).
Ajustează pragul după nevoile tale (de ex. `-mmin +720` pentru 12 h).

> Nu șterge `data/.config.php` (parola) și `data/feedback.txt` decât intenționat.

### Monitorizarea spațiului pe disc

```bash
du -sh /var/www/quiz/data
ls -1 /var/www/quiz/data/live | wc -l   # câte sesiuni active/reziduale
```

### Verificarea permisiunilor

Dacă aplicația nu poate scrie (jocurile nu se creează), verifică:

```bash
ls -ld /var/www/quiz /var/www/quiz/data
sudo chown -R www-data:www-data /var/www/quiz/data
```

---

## 13. Backup și restaurare

Ce contează pentru backup: **`data/.config.php`** (parola) și, dacă folosești stocarea
server a quiz-urilor, **`data/quizzes/`**. Sesiunile live (`data/live/`) sunt de obicei
efemere și nu necesită backup.

### Backup

```bash
sudo tar czf quiz-backup-$(date +%F).tar.gz -C /var/www/quiz data
```

Păstrează arhiva într-un loc sigur (conține hash-ul parolei).

### Restaurare

```bash
sudo tar xzf quiz-backup-2026-07-01.tar.gz -C /var/www/quiz
sudo chown -R www-data:www-data /var/www/quiz/data
```

---

## 14. Actualizarea aplicației

Fiind un singur fișier, actualizarea e simplă:

1. **Fă backup** la `data/` (secțiunea 13) și păstrează o copie a vechiului `index.php`.
2. Înlocuiește `index.php` cu versiunea nouă.
3. Repară proprietarul dacă e nevoie: `sudo chown www-data:www-data /var/www/quiz/index.php`.
4. Reîncarcă pagina și **testează** un joc de probă.

Datele din `data/` (inclusiv parola) **se păstrează** peste actualizare, fiindcă sunt
separate de `index.php`. Formatul quiz-urilor este stabil între versiuni, deci quiz-urile
existente rămân compatibile.

---

## 15. Resetarea parolei de gazdă

Dacă ai uitat parola:

1. Fă backup la `data/`.
2. **Șterge** (sau redenumește) fișierul de configurare:

   ```bash
   sudo mv /var/www/quiz/data/.config.php /var/www/quiz/data/.config.php.bak
   ```

3. Reîncarcă aplicația. Fiindcă nu mai există parolă setată, vei putea **stabili una nouă**
   la fel ca la prima configurare (secțiunea 8).

> Aceasta doar resetează parola; **nu** afectează quiz-urile sau sesiunile. Fișierul vechi
> `.config.php.bak` conține doar hash-ul vechi — șterge-l după ce te-ai asigurat că totul e
> în regulă.

---

## 16. Depanare

**Pagina e albă / eroare 500.**
Verifică logul de erori PHP/al serverului:
- Apache: `sudo tail -f /var/log/apache2/error.log`
- Nginx + PHP-FPM: `sudo tail -f /var/log/nginx/error.log` și `/var/log/php8.3-fpm.log`
Cauze frecvente: versiune PHP prea veche (<8.0), permisiuni greșite la director.

**Jocurile/activitățile nu se creează („write failed").**
Directorul `data/` (sau `data/live/`) nu e scriibil. Repară proprietarul/permisiunile:
```bash
sudo chown -R www-data:www-data /var/www/quiz/data
sudo chmod -R u+rwX /var/www/quiz/data
```

**Fișierele din `data/` sunt accesibile din browser.**
Nu ai blocat directorul. Pe Nginx adaugă blocul `location ^~ /data/ { deny all; }`
(secțiunea 6); pe Apache verifică `AllowOverride All` sau blochează în vhost (secțiunea 5).
Confirmă cu o cerere la `.../data/.config.php` (trebuie 403).

**„bad csrf" la acțiunile de gazdă.**
Sesiunea a expirat sau cookie-urile sunt blocate. Reautentifică-te ca gazdă. Verifică și că
cookie-urile de sesiune funcționează (domeniu/HTTPS corect).

**Instalarea ca aplicație (PWA) nu apare.**
PWA necesită HTTPS. Configurează un certificat (secțiunea 11).

**Diacriticele/potrivirea la răspuns liber par ciudate.**
Aplicația are o rezervă internă pentru diacriticele românești chiar și fără extensia
`intl`. Dacă totuși vezi probleme, instalarea extensiei `php-intl` și `php-mbstring` poate
ajuta:
```bash
sudo apt install php8.3-intl php8.3-mbstring && sudo systemctl reload php8.3-fpm
```

**Sesiunile PHP nu persistă (te deconectează des).**
Verifică setările `session.save_path` din `php.ini` și că directorul respectiv e scriibil;
verifică ceasul serverului și configurarea cookie-urilor.

---

## 17. Performanță și limite

- **Fără bază de date**, fiecare răspuns la un joc live implică o citire-modificare-scriere
  cu blocare a fișierului sesiunii. Pentru dimensiuni tipice de atelier (zeci de
  participanți) este perfect adecvat.
- **La grupuri foarte mari** (multe sute de participanți simultan pe aceeași sesiune),
  contenția pe fișierul sesiunii poate crește latența. Aplicația e gândită pentru ateliere,
  nu pentru evenimente de masă de tip stadion.
- **Limite implicite** (din motive de robustețe): maximum **100 de întrebări** per quiz,
  lungimi maxime pentru titlu/întrebări/răspunsuri/nume/mesaje, dimensiune maximă a
  cererii. Aceste limite protejează serverul de abuz și de intrări accidentale enorme.
- **Curăță `data/live/`** periodic (secțiunea 12) ca să eviți acumularea de fișiere.

---

## 18. Checklist înainte de un atelier

- [ ] `index.php` este pe server și pagina principală se încarcă.
- [ ] Accesul web la `data/` este **blocat** (verificat cu o cerere la `.../data/.config.php`).
- [ ] **HTTPS** activ (necesar pentru PWA și securitate).
- [ ] Parola de gazdă este **setată** și o cunoști.
- [ ] Ai făcut un **joc live de probă cap-coadă** cu 2–3 dispozitive reale, pe rețeaua pe
      care o vei folosi, testând **fiecare tip de întrebare** (grilă, adevărat-fals, răspuns
      liber, numeric) și, dacă le folosești, **echipele** și **activitățile de audiență**.
- [ ] Ai testat **codul de intrare** și **QR-ul** de pe un telefon din sală.
- [ ] Ai un plan de rezervă (de ex. ediția offline HTML) în caz de probleme de rețea.
- [ ] Sarcina de **curățare** a sesiunilor este configurată (cron) sau știi să o faci manual.
- [ ] Ai un **backup** recent al `data/`.

---

## 19. Notă onestă despre testare

Această aplicație a fost dezvoltată și verificată intens la nivel de **logică** (formule de
punctaj, potrivire de text, agregare, validări), dar **fluxul integrat cap-coadă pe un
server real, în browser, cu mai multe dispozitive** este responsabilitatea ta de a-l valida
înainte de a te baza pe el public. În special:

- **Rulează un joc live complet** pe infrastructura ta reală înainte de un atelier — aici
  ies la iveală eventualele probleme de integrare care nu apar la testarea pe componente.
- **Testează combinațiile** pe care le vei folosi efectiv (de ex. prezentare cu Q&A moderat
  + filtru, echipe + întrebări numerice, reintrarea offline în PWA).
- **Verifică anteturile și cookie-urile de sesiune** dacă expui aplicația pe internet, nu
  doar într-o rețea locală de atelier.
- Ține minte că **filtrul de limbaj este slab** — bazează-te pe moderarea Q&A și pe
  supraveghere umană pentru conținutul public.

Tratează-l ca pe un instrument solid, dar **testează-l tu cap-coadă** în condițiile tale
înainte de a-l pune în fața unui public.

---

*Undava — ediția server. Pentru utilizarea propriu-zisă (creare quiz-uri, joc,
activități), vezi **Manualul de utilizator**.*

</script>
<script>
"use strict";
window.QFF = window.QFF || <?php echo json_encode($QFF_CLIENT, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
/* =========================================================================
   Undava — single-file quiz game (Kahoot-style)
   ========================================================================= */

/* ---------------- i18n ---------------- */
const I18N = {
  ro:{
    tagline:"Quiz fără frontiere",
    home_kicker:"Joc de quiz • offline • gratuit",
    home_title_1:"Învață, joacă,",
    home_title_2:"câștigă",
    home_sub:"Un joc de întrebări în spiritul Kahoot — fără conturi, fără reclame, fără internet. Creează propriile quiz-uri și joacă cu prietenii pe același dispozitiv.",
    play:"Joacă", create:"Creează quiz", library:"Quiz-urile mele", import:"Importă", settings:"Setări",
    feat_offline:"100% offline", feat_free:"Trade-free", feat_priv:"Zero telemetrie", feat_open:"Un singur fișier",
    lib_title:"Bibliotecă de quiz-uri", lib_sub:"Alege un quiz pentru a-l juca sau edita",
    lib_samples:"Quiz-uri demonstrative", lib_mine:"Quiz-urile mele",
    lib_empty:"Încă nu ai creat niciun quiz. Apasă „Creează quiz” ca să începi.",
    edit:"Editează", duplicate:"Duplică", del:"Șterge", export:"Exportă", playbtn:"Joacă",
    q_count:(n)=>n+(n===1?" întrebare":" întrebări"),
    new_quiz:"Quiz nou", edit_quiz:"Editează quiz-ul",
    quiz_title:"Titlul quiz-ului", quiz_title_ph:"ex: Cultură generală",
    quiz_desc:"Descriere (opțional)", quiz_desc_ph:"O scurtă descriere…",
    questions:"Întrebări", add_question:"Adaugă întrebare",
    question_n:(n)=>"Întrebarea "+n, question_ph:"Scrie întrebarea aici…",
    answer_ph:"Răspuns…", correct:"Corect", add_answer:"+ Adaugă răspuns",
    help_title:"Ghid de utilizare", help_sub:"Manual complet, pas cu pas.", help_nav:"Ghid", help_book_user:"Manual utilizator", help_book_admin:"Manual administrator",
    qtype:"Tip", type_quiz:"Grilă (4 variante)", type_tf:"Adevărat / Fals", type_type:"Răspuns liber", type_num:"Numeric (ghicește)",
    num_target:"Numărul corect", num_target_ph:"ex. 42", num_tol:"Toleranță acceptată",
    num_hint:"Răspunsurile din intervalul ± toleranță sunt corecte; cu cât mai aproape, cu atât mai multe puncte. Toleranță 0 = doar exact.",
    err_num:(n)=>`Întrebarea ${n}: introdu un număr valid.`, num_answer_ph:"Scrie un număr…",
    acc_primary_ph:"Răspunsul corect (cel afișat)", acc_alt_ph:"Variantă acceptată (sinonim, altă scriere)", acc_add:"Adaugă variantă acceptată",
    type_answer_ph:"Scrie răspunsul…", type_submit:"Trimite", type_need_answer:"Scrie un răspuns.", you_wrote:"Ai scris:",
    gh_typing:"Participanții își scriu răspunsul…", gh_gotit:"corecte",
    acc_hint:"Se acceptă și mici greșeli de tipar. Prima variantă e cea afișată ca răspuns corect.",
    err_acc:(n)=>`Întrebarea ${n}: adaugă cel puțin un răspuns acceptat.`,
    type_not_live:"Întrebările cu răspuns liber merg deocamdată doar în modul solo/hotseat (nu live/temă).",
    tf_true:"Adevărat", tf_false:"Fals",
    time_limit:"Timp", pts:"Puncte", pts_std:"Standard", pts_dbl:"Dublu",
    sec:"s", save:"Salvează quiz-ul", cancel:"Renunță",
    saved:"Quiz salvat!", deleted:"Quiz șters",
    err_title:"Dă un titlu quiz-ului.", err_noq:"Adaugă cel puțin o întrebare.",
    err_qtext:(n)=>"Întrebarea "+n+" nu are text.",
    err_ans:(n)=>"Întrebarea "+n+" are nevoie de cel puțin 2 răspunsuri.",
    err_corr:(n)=>"Marchează răspunsul corect la întrebarea "+n+".",
    confirm_del:"Sigur ștergi acest quiz?",
    setup_title:"Pregătește jocul", choose_mode:"Mod de joc",
    mode_solo:"Solo", mode_solo_d:"Joci singur și îți baţi recordul.",
    mode_hot:"Pe rând", mode_hot_d:"Mai mulți jucători pe același dispozitiv.",
    players:"Jucători", player_ph:"Numele jucătorului",
    add_player:"+ Adaugă jucător", your_name:"Numele tău", you:"Tu",
    start_game:"Începe jocul", need_player:"Adaugă cel puțin un jucător.",
    get_ready:"Pregătește-te!", q_of:(a,b)=>"Întrebarea "+a+" din "+b,
    tap_answer:"Atinge răspunsul corect", keys_hint:"Apasă 1-4 sau atinge un răspuns",
    pass_to:"Predă dispozitivul lui", tap_start:"Atinge când ești gata",
    ready_q:"Pregătit?", time_up:"Timpul a expirat!",
    verdict_ok:"Corect!", verdict_no:"Greșit!", verdict_miss:"Fără răspuns",
    correct_was:"Răspuns corect:", pts_earned:"puncte", streak:(n)=>"Serie de "+n+" 🔥",
    continue:"Continuă", scoreboard:"Clasament",
    podium_title:"Felicitări!", winner_is:"Câștigător",
    play_again:"Joacă din nou", back_home:"Acasă", final_scores:"Clasament final",
    stat_correct:"Corecte", stat_acc:"Acuratețe", stat_pts:"Puncte", stat_streak:"Serie max",
    set_title:"Setări", set_lang:"Limbă", set_lang_d:"Limba interfeței",
    set_sound:"Sunet", set_sound_d:"Efecte sonore în timpul jocului",
    set_about:"Undava este un joc single-file, offline și trade-free. Quiz-urile tale sunt salvate doar pe acest dispozitiv. Folosește Exportă pentru a le păstra ca fișiere.",
    close:"Închide",
    import_title:"Importă un quiz", import_drop:"Trage un fișier .json aici sau atinge pentru a alege",
    import_paste:"…sau lipește conținutul JSON:", import_btn:"Importă",
    import_ok:"Quiz importat!", import_err:"Fișier invalid. Verifică formatul JSON.",
    import_paste_ph:'{"title":"…","questions":[…]}',
    quit_q:"Părăsești jocul? Progresul se pierde.", quit:"Ieși din joc",
    guestbook:"Părerea ta",
    fb_title:"Părerea vizitatorilor", fb_sub:"Lasă un gând despre quiz-uri și vezi ce au scris alții.",
    fb_recent:"Feedback recent", fb_empty:"Încă niciun feedback. Fii primul!",
    fb_name:"Nume (opțional)", fb_name_ph:"Cum să-ți spunem?",
    fb_quiz:"Quiz (opțional)", fb_pick_quiz:"— alege un quiz —",
    fb_rating:"Notă", fb_msg:"Mesaj", fb_msg_ph:"Ce ți-a plăcut? Ce am putea îmbunătăți?",
    fb_send:"Trimite feedback", fb_anon:"Anonim",
    fb_need_rating:"Alege o notă (1–5 stele).", fb_need_msg:"Scrie un mesaj.",
    fb_thanks:"Mulțumim pentru feedback!", fb_thanks_mod:"Mulțumim! Mesajul va apărea după aprobare.",
    fb_leave:"Lasă un feedback", fb_local_note:"(salvat local, pe acest dispozitiv)",
    fb_approve:"Aprobă", fb_pending:"în așteptare",
    set_admin:"Administrator", set_admin_on:"Ești autentificat ca administrator.", set_admin_off:"Autentifică-te pentru a edita quiz-urile.",
    login:"Autentificare", logout:"Ieși", password:"Parolă", password_again:"Repetă parola",
    admin_login:"Autentificare administrator", admin_login_d:"Introdu parola pentru a putea crea și edita quiz-uri.",
    admin_setup:"Setează parola de administrator", admin_setup_d:"Acesta este primul acces. Alege o parolă pentru zona de administrare.",
    admin_create:"Creează parola", admin_ok:"Autentificat!", admin_out:"Te-ai deconectat.", admin_bad:"Parolă greșită.",
    err_pw:"Introdu parola.", err_pw_short:"Parola trebuie să aibă minim 6 caractere.", err_pw_match:"Parolele nu coincid.",
    net_err:"Eroare de rețea. Verifică conexiunea.",
    live_nav:"În direct",
    live_title:"Sesiune live", live_sub:"Nor de cuvinte, sondaje și întrebări — pe telefoanele publicului, în timp real.",
    live_join_code:"Intră cu un cod", live_code_ph:"COD", live_join:"Intră", live_host_new:"Sau pornește o activitate nouă",
    live_t_cloud:"Nor de cuvinte", live_t_cloud_d:"Publicul trimite cuvinte, apar live.",
    live_t_poll:"Sondaj live", live_t_poll_d:"Vot pe opțiuni, bare în timp real.",
    live_t_qa:"Întrebări & idei", live_t_qa_d:"Mesaje cu voturi de la public.",
    live_options:"Opțiuni", live_option:"Opțiunea", live_add_option:"Adaugă opțiune",
    live_max_words:"Câte cuvinte poate trimite o persoană",
    live_prompt:"Întrebarea / tema", live_prompt_ph:"Ex: Ce cuvânt descrie viitorul?", live_launch:"Pornește sesiunea",
    live_host_login:"Autentifică-te ca administrator pentru a găzdui o sesiune.",
    live_need_prompt:"Scrie o întrebare sau o temă.", live_need_opts:"Adaugă cel puțin 2 opțiuni.", live_need_code:"Introdu un cod.",
    live_back:"Închide", live_open:"DESCHIS", live_closed_b:"ÎNCHIS",
    live_pause:"Pauză", live_resume:"Reia", live_clear:"Golește", live_clear_q:"Ștergi toate răspunsurile?",
    live_end:"Termină", live_end_q:"Închizi definitiv sesiunea? Datele se șterg.", live_ended:"Sesiune închisă.",
    live_responses:"răspunsuri", live_join_at:"Intră la", live_copy:"Copiază linkul", live_share:"Distribuie", live_copied:"Link copiat!",
    live_leave:"Ieși", live_loading:"Se încarcă…", live_notfound:"Sesiunea nu există sau s-a încheiat.",
    live_closed_note:"Gazda a pus răspunsurile pe pauză.", live_live_results:"Rezultate live",
    live_word_ph:"Scrie un cuvânt…", live_qa_ph:"Scrie întrebarea sau ideea ta…",
    live_send:"Trimite", live_sent:"Trimis!", live_slow:"Prea repede — așteaptă o clipă.", live_closed:"Răspunsurile sunt închise.",
    live_need_text:"Scrie ceva mai întâi.",
    live_words_left:(n)=>`Mai poți trimite ${n} cuvânt(e).`, live_thanks_words:"Mulțumim! Vezi norul de cuvinte mai jos.",
    live_voted:"Vot înregistrat!", live_waiting:"Se așteaptă răspunsuri…", live_show:"Arată", live_hide:"Ascunde",
    live_png_name:"nor-de-cuvinte", live_json_name:"rezultate-live",
    mode_live:"Live (mulți jucători)", mode_live_d:"Găzduiește pe ecran mare; publicul joacă de pe telefoane.",
    game_host:"Găzduiește live", game_join_note:"Jucătorii se alătură de pe telefoane, scanând codul QR sau introducând codul afișat.",
    game_players:"jucători", game_waiting_players:"Aștept jucători… împărtășește codul!",
    game_start:"Începe jocul", game_reveal:"Arată răspunsul", game_next:"Următoarea", game_results:"Rezultate", game_replay:"Joacă din nou",
    game_pick_name:"Alege un nume și un avatar", game_nickname:"Porecla ta", game_enter:"Intră în joc", game_need_name:"Scrie o poreclă.",
    game_youre_in:"Ești în joc!", game_look_screen:"Privește ecranul principal.", game_lobby_closed:"Jocul a început deja.",
    game_locked:"Răspuns trimis!", game_wait_others:"Așteaptă ceilalți jucători…", game_times_up:"A expirat timpul!",
    game_correct:"Corect!", game_wrong:"Greșit", game_missed:"Fără răspuns", game_your_rank:"Locul tău:", game_finished:"Joc terminat!",
    shuffle_opt:"Amestecă întrebările și variantele", nick_gen:"Poreclă la întâmplare",
    team_opt:"Mod pe echipe", team_word:"echipe", team_pick:"Alege-ți echipa", team_standings:"Clasament pe echipe",
    spin_nav:"Roata", spin_title:"Roata norocului", spin_sub:"Introdu nume sau opțiuni, apoi învârte pentru a alege la întâmplare.",
    spin_go:"Învârte", spin_items:"Elemente", spin_ph:"Un element pe linie…\nAna\nBogdan\nCristina", spin_need:"Adaugă cel puțin 2 elemente.",
    spin_elim:"Elimină câștigătorul după fiecare tragere", spin_shuffle:"Amestecă", spin_reset:"Resetează", spin_default:"Ana|Bogdan|Cristina|David|Elena|Florin",
    live_filter:"Filtru de limbaj", live_filter_off:"Oprit", live_filter_on:"Pornit", live_filter_hint:"Ascunde automat cuvintele vulgare din răspunsuri (le înlocuiește cu ***).",
    live_qa_mod:"Moderare întrebări", live_qa_mod_off:"Fără (toate apar)", live_qa_mod_on:"Cu aprobare",
    live_qa_mod_hint:"Cu aprobare, întrebările apar publicului doar după ce le aprobi tu.",
    qa_approve:"Aprobă", qa_pending:"În așteptare", qa_pending_count:(n)=>`${n} în așteptare`, live_sent_mod:"Trimisă spre aprobare.",
    deck_export:"Exportă", deck_import:"Importă", deck_import_empty:"Fișierul nu conține activități valide.", deck_import_bad:"Fișier invalid.", deck_imported:(n)=>`${n} activități importate.`,
    live_mode_single:"O activitate", live_mode_deck:"Prezentare",
    deck_title:"Titlul prezentării", deck_title_ph:"ex. Atelier Atlantykron — feedback",
    deck_slides:"Activități", deck_empty:"Nicio activitate încă. Alege un tip mai sus și adaugă.",
    deck_add_new:"Adaugă o activitate", deck_add_slide:"Adaugă în prezentare", deck_added:"Activitate adăugată.",
    deck_launch:"Lansează prezentarea", deck_need_slides:"Adaugă cel puțin o activitate.", deck_full:"Maximum 50 de activități.",
    deck_default_title:"Prezentare", deck_slide:"Activitatea", deck_prev:"Înapoi", deck_next:"Următoarea",
    live_t_scale:"Scale (Likert)", live_t_scale_d:"Mai multe afirmații, notate 1–5 (dezacord→acord).",
    live_t_points:"100 de puncte", live_t_points_d:"Participanții împart un buget de puncte între opțiuni.",
    live_statements:"Afirmații", live_statement:"Afirmația", live_add_stmt:"Adaugă afirmație",
    live_scale_hint:"Fiecare afirmație se notează pe o scală de la 1 (dezacord total) la 5 (acord total).",
    live_points_opts:"Opțiuni", live_points_hint:"Fiecare participant împarte 100 de puncte între aceste opțiuni.",
    live_need_stmts:"Adaugă cel puțin 2 afirmații.",
    scale_lo:"dezacord total", scale_hi:"acord total", scale_all:"Notează toate afirmațiile.",
    pt_remaining:"Rămase:", pt_useall:"Folosește toate punctele", pt_budget_each:(n)=>`${n} puncte de persoană`,
    live_t_rating:"Rating / NPS", live_t_rating_d:"Notă cu stele sau scor NPS 0–10.",
    live_t_rank:"Clasament", live_t_rank_d:"Participanții ordonează opțiunile după preferință.",
    live_rating_scale:"Tip de scală", live_rank_opts:"Opțiuni de ordonat", rank_submit:"Trimite ordinea",
    rt_avg:"medie", rt_prom:"Promotori", rt_pass:"Pasivi", rt_det:"Detractori", rk_foot:"ordonat după rangul mediu (mai mic = mai bun)",
    nps_low:"Deloc probabil", nps_high:"Extrem de probabil",
    qa_answered:"Răspuns", qa_star:"Evidențiază", qa_mark:"Marchează ca răspuns",
    offline_banner:"Ești offline — solo, biblioteca și editorul funcționează. Modurile live revin la reconectare.",
    install_hint:"Instalează Undava ca aplicație", install_btn:"Instalează", offline_feature:"Indisponibil offline.", online_back:"Ești din nou online.",
    mode_assign:"Temă (autonom)", mode_assign_d:"Atribuie un quiz; fiecare îl rezolvă singur, în ritm propriu.",
    assign_host:"Atribuie tema", assign_note:"Participanții intră de pe telefoane și rezolvă singuri, în ritm propriu. Rezultatele se adună aici.",
    assign_intro:(n)=>`${n} întrebări · în ritmul tău`, assign_start:"Începe",
    assign_done:"au terminat", assign_joined:"înscriși", assign_none_done:"Încă nimeni n-a terminat.",
    assign_closed:"Tema este închisă.", assign_closed_note:"Gazda a închis tema.",
    assign_complete:"Ai terminat tema!", assign_finish:"Vezi rezultatul",
    game_report:"Raport", report_title:"Raport joc", report_byq:"Pe întrebări", report_byp:"Pe jucători",
    report_players:"jucători", report_questions:"întrebări", report_avg_score:"scor mediu", report_accuracy:"acuratețe", report_avg_time:"timp mediu",
    report_correct:"corecte", report_answered:"au răspuns", report_csv:"CSV", report_json:"JSON", report_print:"Printează",
    report_easy:"ușoară", report_med:"medie", report_hard:"grea", report_back:"Înapoi", report_no_data:"Încă nu sunt date.", report_player:"Jucător",
  },
  en:{
    tagline:"Quiz without borders",
    home_kicker:"Quiz game • offline • free",
    home_title_1:"Learn, play,",
    home_title_2:"win",
    home_sub:"A quiz game in the spirit of Kahoot — no accounts, no ads, no internet. Build your own quizzes and play with friends on the same device.",
    play:"Play", create:"Create quiz", library:"My quizzes", import:"Import", settings:"Settings",
    feat_offline:"100% offline", feat_free:"Trade-free", feat_priv:"Zero telemetry", feat_open:"Single file",
    lib_title:"Quiz library", lib_sub:"Pick a quiz to play or edit",
    lib_samples:"Sample quizzes", lib_mine:"My quizzes",
    lib_empty:"You haven't created any quizzes yet. Tap “Create quiz” to start.",
    edit:"Edit", duplicate:"Duplicate", del:"Delete", export:"Export", playbtn:"Play",
    q_count:(n)=>n+(n===1?" question":" questions"),
    new_quiz:"New quiz", edit_quiz:"Edit quiz",
    quiz_title:"Quiz title", quiz_title_ph:"e.g. General knowledge",
    quiz_desc:"Description (optional)", quiz_desc_ph:"A short description…",
    questions:"Questions", add_question:"Add question",
    question_n:(n)=>"Question "+n, question_ph:"Type the question here…",
    answer_ph:"Answer…", correct:"Correct", add_answer:"+ Add answer",
    help_title:"User guide", help_sub:"Complete step-by-step manual.", help_nav:"Guide", help_book_user:"User manual", help_book_admin:"Admin manual",
    qtype:"Type", type_quiz:"Multiple choice (4)", type_tf:"True / False", type_type:"Free answer", type_num:"Numeric (guess)",
    num_target:"Correct number", num_target_ph:"e.g. 42", num_tol:"Accepted tolerance",
    num_hint:"Answers within ± tolerance are correct; the closer, the more points. Tolerance 0 = exact only.",
    err_num:(n)=>`Question ${n}: enter a valid number.`, num_answer_ph:"Type a number…",
    acc_primary_ph:"The correct answer (shown)", acc_alt_ph:"Accepted variant (synonym, spelling)", acc_add:"Add accepted variant",
    type_answer_ph:"Type your answer…", type_submit:"Submit", type_need_answer:"Type an answer.", you_wrote:"You wrote:",
    gh_typing:"Participants are typing…", gh_gotit:"correct",
    acc_hint:"Small typos are accepted too. The first variant is shown as the correct answer.",
    err_acc:(n)=>`Question ${n}: add at least one accepted answer.`,
    type_not_live:"Free-answer questions currently work in solo/hotseat only (not live/homework).",
    tf_true:"True", tf_false:"False",
    time_limit:"Time", pts:"Points", pts_std:"Standard", pts_dbl:"Double",
    sec:"s", save:"Save quiz", cancel:"Cancel",
    saved:"Quiz saved!", deleted:"Quiz deleted",
    err_title:"Give the quiz a title.", err_noq:"Add at least one question.",
    err_qtext:(n)=>"Question "+n+" has no text.",
    err_ans:(n)=>"Question "+n+" needs at least 2 answers.",
    err_corr:(n)=>"Mark the correct answer for question "+n+".",
    confirm_del:"Delete this quiz for good?",
    setup_title:"Set up the game", choose_mode:"Game mode",
    mode_solo:"Solo", mode_solo_d:"Play alone and beat your high score.",
    mode_hot:"Pass & play", mode_hot_d:"Several players on the same device.",
    players:"Players", player_ph:"Player name",
    add_player:"+ Add player", your_name:"Your name", you:"You",
    start_game:"Start game", need_player:"Add at least one player.",
    get_ready:"Get ready!", q_of:(a,b)=>"Question "+a+" of "+b,
    tap_answer:"Tap the correct answer", keys_hint:"Press 1-4 or tap an answer",
    pass_to:"Pass the device to", tap_start:"Tap when you're ready",
    ready_q:"Ready?", time_up:"Time's up!",
    verdict_ok:"Correct!", verdict_no:"Wrong!", verdict_miss:"No answer",
    correct_was:"Correct answer:", pts_earned:"points", streak:(n)=>n+" in a row 🔥",
    continue:"Continue", scoreboard:"Scoreboard",
    podium_title:"Congratulations!", winner_is:"Winner",
    play_again:"Play again", back_home:"Home", final_scores:"Final scores",
    stat_correct:"Correct", stat_acc:"Accuracy", stat_pts:"Points", stat_streak:"Best streak",
    set_title:"Settings", set_lang:"Language", set_lang_d:"Interface language",
    set_sound:"Sound", set_sound_d:"Sound effects during play",
    set_about:"Undava is a single-file, offline, trade-free game. Your quizzes are stored only on this device. Use Export to keep them as files.",
    close:"Close",
    import_title:"Import a quiz", import_drop:"Drop a .json file here or tap to choose",
    import_paste:"…or paste JSON content:", import_btn:"Import",
    import_ok:"Quiz imported!", import_err:"Invalid file. Check the JSON format.",
    import_paste_ph:'{"title":"…","questions":[…]}',
    quit_q:"Leave the game? Progress will be lost.", quit:"Quit game",
    guestbook:"Feedback",
    fb_title:"Visitor feedback", fb_sub:"Leave a thought about the quizzes and see what others wrote.",
    fb_recent:"Recent feedback", fb_empty:"No feedback yet. Be the first!",
    fb_name:"Name (optional)", fb_name_ph:"What should we call you?",
    fb_quiz:"Quiz (optional)", fb_pick_quiz:"— pick a quiz —",
    fb_rating:"Rating", fb_msg:"Message", fb_msg_ph:"What did you like? What could be better?",
    fb_send:"Send feedback", fb_anon:"Anonymous",
    fb_need_rating:"Pick a rating (1–5 stars).", fb_need_msg:"Write a message.",
    fb_thanks:"Thanks for your feedback!", fb_thanks_mod:"Thanks! Your message will appear after approval.",
    fb_leave:"Leave feedback", fb_local_note:"(saved locally, on this device)",
    fb_approve:"Approve", fb_pending:"pending",
    set_admin:"Administrator", set_admin_on:"You are signed in as administrator.", set_admin_off:"Sign in to edit quizzes.",
    login:"Sign in", logout:"Sign out", password:"Password", password_again:"Repeat password",
    admin_login:"Administrator sign-in", admin_login_d:"Enter the password to create and edit quizzes.",
    admin_setup:"Set administrator password", admin_setup_d:"This is the first visit. Choose a password for the admin area.",
    admin_create:"Create password", admin_ok:"Signed in!", admin_out:"Signed out.", admin_bad:"Wrong password.",
    err_pw:"Enter the password.", err_pw_short:"Password must be at least 6 characters.", err_pw_match:"Passwords don't match.",
    net_err:"Network error. Check your connection.",
    live_nav:"Live",
    live_title:"Live session", live_sub:"Word cloud, polls and questions — on the audience's phones, in real time.",
    live_join_code:"Join with a code", live_code_ph:"CODE", live_join:"Join", live_host_new:"Or start a new activity",
    live_t_cloud:"Word cloud", live_t_cloud_d:"The audience sends words, shown live.",
    live_t_poll:"Live poll", live_t_poll_d:"Vote on options, real-time bars.",
    live_t_qa:"Questions & ideas", live_t_qa_d:"Upvotable messages from the crowd.",
    live_options:"Options", live_option:"Option", live_add_option:"Add option",
    live_max_words:"How many words one person can send",
    live_prompt:"Question / topic", live_prompt_ph:"E.g. Which word describes the future?", live_launch:"Start session",
    live_host_login:"Sign in as administrator to host a session.",
    live_need_prompt:"Write a question or topic.", live_need_opts:"Add at least 2 options.", live_need_code:"Enter a code.",
    live_back:"Close", live_open:"OPEN", live_closed_b:"CLOSED",
    live_pause:"Pause", live_resume:"Resume", live_clear:"Clear", live_clear_q:"Delete all responses?",
    live_end:"End", live_end_q:"End the session for good? Data will be deleted.", live_ended:"Session closed.",
    live_responses:"responses", live_join_at:"Join at", live_copy:"Copy link", live_share:"Share", live_copied:"Link copied!",
    live_leave:"Leave", live_loading:"Loading…", live_notfound:"Session not found or has ended.",
    live_closed_note:"The host paused responses.", live_live_results:"Live results",
    live_word_ph:"Type a word…", live_qa_ph:"Type your question or idea…",
    live_send:"Send", live_sent:"Sent!", live_slow:"Too fast — wait a moment.", live_closed:"Responses are closed.",
    live_need_text:"Write something first.",
    live_words_left:(n)=>`You can send ${n} more word(s).`, live_thanks_words:"Thanks! See the word cloud below.",
    live_voted:"Vote counted!", live_waiting:"Waiting for responses…", live_show:"Show", live_hide:"Hide",
    live_png_name:"word-cloud", live_json_name:"live-results",
    mode_live:"Live (multiplayer)", mode_live_d:"Host on the big screen; the audience plays on phones.",
    game_host:"Host live", game_join_note:"Players join from their phones by scanning the QR code or entering the displayed code.",
    game_players:"players", game_waiting_players:"Waiting for players… share the code!",
    game_start:"Start game", game_reveal:"Reveal answer", game_next:"Next", game_results:"Results", game_replay:"Play again",
    game_pick_name:"Pick a name and avatar", game_nickname:"Your nickname", game_enter:"Enter game", game_need_name:"Enter a nickname.",
    game_youre_in:"You're in!", game_look_screen:"Look at the main screen.", game_lobby_closed:"The game already started.",
    game_locked:"Answer submitted!", game_wait_others:"Waiting for other players…", game_times_up:"Time's up!",
    game_correct:"Correct!", game_wrong:"Wrong", game_missed:"No answer", game_your_rank:"Your rank:", game_finished:"Game over!",
    shuffle_opt:"Shuffle questions & answers", nick_gen:"Random nickname",
    team_opt:"Team mode", team_word:"teams", team_pick:"Pick your team", team_standings:"Team standings",
    spin_nav:"Wheel", spin_title:"Spinner wheel", spin_sub:"Enter names or options, then spin to pick at random.",
    spin_go:"Spin", spin_items:"Items", spin_ph:"One item per line…\nAlice\nBob\nCarol", spin_need:"Add at least 2 items.",
    spin_elim:"Remove the winner after each spin", spin_shuffle:"Shuffle", spin_reset:"Reset", spin_default:"Alice|Bob|Carol|Dave|Eve|Frank",
    live_filter:"Profanity filter", live_filter_off:"Off", live_filter_on:"On", live_filter_hint:"Automatically masks vulgar words in responses (replaces them with ***).",
    live_qa_mod:"Question moderation", live_qa_mod_off:"Off (all shown)", live_qa_mod_on:"Require approval",
    live_qa_mod_hint:"With approval, questions appear to the audience only after you approve them.",
    qa_approve:"Approve", qa_pending:"Pending", qa_pending_count:(n)=>`${n} pending`, live_sent_mod:"Sent for approval.",
    deck_export:"Export", deck_import:"Import", deck_import_empty:"No valid activities in the file.", deck_import_bad:"Invalid file.", deck_imported:(n)=>`${n} activities imported.`,
    live_mode_single:"Single activity", live_mode_deck:"Presentation",
    deck_title:"Presentation title", deck_title_ph:"e.g. Atlantykron workshop — feedback",
    deck_slides:"Activities", deck_empty:"No activities yet. Pick a type above and add one.",
    deck_add_new:"Add an activity", deck_add_slide:"Add to presentation", deck_added:"Activity added.",
    deck_launch:"Launch presentation", deck_need_slides:"Add at least one activity.", deck_full:"Maximum 50 activities.",
    deck_default_title:"Presentation", deck_slide:"Activity", deck_prev:"Previous", deck_next:"Next",
    live_t_scale:"Scales (Likert)", live_t_scale_d:"Several statements rated 1–5 (disagree→agree).",
    live_t_points:"100 points", live_t_points_d:"Participants split a points budget across options.",
    live_statements:"Statements", live_statement:"Statement", live_add_stmt:"Add statement",
    live_scale_hint:"Each statement is rated on a 1 (strongly disagree) to 5 (strongly agree) scale.",
    live_points_opts:"Options", live_points_hint:"Each participant splits 100 points across these options.",
    live_need_stmts:"Add at least 2 statements.",
    scale_lo:"strongly disagree", scale_hi:"strongly agree", scale_all:"Rate every statement.",
    pt_remaining:"Remaining:", pt_useall:"Use all your points", pt_budget_each:(n)=>`${n} points each`,
    live_t_rating:"Rating / NPS", live_t_rating_d:"Star rating or NPS 0–10 score.",
    live_t_rank:"Ranking", live_t_rank_d:"Participants order the options by preference.",
    live_rating_scale:"Scale type", live_rank_opts:"Options to rank", rank_submit:"Submit ranking",
    rt_avg:"average", rt_prom:"Promoters", rt_pass:"Passives", rt_det:"Detractors", rk_foot:"ordered by average rank (lower = better)",
    nps_low:"Not at all likely", nps_high:"Extremely likely",
    qa_answered:"Answered", qa_star:"Highlight", qa_mark:"Mark as answered",
    offline_banner:"You're offline — solo, library and editor work. Live modes return when reconnected.",
    install_hint:"Install Undava as an app", install_btn:"Install", offline_feature:"Unavailable offline.", online_back:"You're back online.",
    mode_assign:"Self-paced", mode_assign_d:"Assign a quiz; everyone solves it on their own, at their own pace.",
    assign_host:"Assign homework", assign_note:"Participants join from their phones and solve at their own pace. Results collect here.",
    assign_intro:(n)=>`${n} questions · at your pace`, assign_start:"Start",
    assign_done:"finished", assign_joined:"joined", assign_none_done:"No one has finished yet.",
    assign_closed:"This assignment is closed.", assign_closed_note:"The host closed the assignment.",
    assign_complete:"You finished!", assign_finish:"See result",
    game_report:"Report", report_title:"Game report", report_byq:"By question", report_byp:"By player",
    report_players:"players", report_questions:"questions", report_avg_score:"avg score", report_accuracy:"accuracy", report_avg_time:"avg time",
    report_correct:"correct", report_answered:"answered", report_csv:"CSV", report_json:"JSON", report_print:"Print",
    report_easy:"easy", report_med:"medium", report_hard:"hard", report_back:"Back", report_no_data:"No data yet.", report_player:"Player",
  },
  fr:{
    tagline:"Quiz sans frontières",
    home_kicker:"Jeu de quiz • hors ligne • gratuit",
    home_title_1:"Apprends, joue,",
    home_title_2:"gagne",
    home_sub:"Un jeu de quiz dans l'esprit de Kahoot — sans compte, sans pub, sans internet. Crée tes propres quiz et joue avec tes amis sur le même appareil.",
    play:"Jouer", create:"Créer un quiz", library:"Mes quiz", import:"Importer", settings:"Paramètres",
    feat_offline:"100% hors ligne", feat_free:"Sans commerce", feat_priv:"Zéro télémétrie", feat_open:"Fichier unique",
    lib_title:"Bibliothèque de quiz", lib_sub:"Choisis un quiz à jouer ou à modifier",
    lib_samples:"Quiz d'exemple", lib_mine:"Mes quiz",
    lib_empty:"Tu n'as encore créé aucun quiz. Appuie sur « Créer un quiz » pour commencer.",
    edit:"Modifier", duplicate:"Dupliquer", del:"Supprimer", export:"Exporter", playbtn:"Jouer",
    q_count:(n)=>n+(n===1?" question":" questions"),
    new_quiz:"Nouveau quiz", edit_quiz:"Modifier le quiz",
    quiz_title:"Titre du quiz", quiz_title_ph:"ex. Culture générale",
    quiz_desc:"Description (facultatif)", quiz_desc_ph:"Une courte description…",
    questions:"Questions", add_question:"Ajouter une question",
    question_n:(n)=>"Question "+n, question_ph:"Écris la question ici…",
    answer_ph:"Réponse…", correct:"Correcte", add_answer:"+ Ajouter une réponse",
    help_title:"Guide d'utilisation", help_sub:"Manuel complet, étape par étape.", help_nav:"Guide", help_book_user:"Manuel utilisateur", help_book_admin:"Manuel administrateur",
    qtype:"Type", type_quiz:"Choix multiple (4)", type_tf:"Vrai / Faux", type_type:"Réponse libre", type_num:"Numérique (devine)",
    num_target:"Nombre correct", num_target_ph:"ex. 42", num_tol:"Tolérance acceptée",
    num_hint:"Les réponses dans l'intervalle ± tolérance sont correctes ; plus c'est proche, plus il y a de points. Tolérance 0 = exact uniquement.",
    err_num:(n)=>`Question ${n} : saisis un nombre valide.`, num_answer_ph:"Écris un nombre…",
    acc_primary_ph:"La réponse correcte (affichée)", acc_alt_ph:"Variante acceptée (synonyme, orthographe)", acc_add:"Ajouter une variante acceptée",
    type_answer_ph:"Écris ta réponse…", type_submit:"Envoyer", type_need_answer:"Écris une réponse.", you_wrote:"Tu as écrit :",
    gh_typing:"Les participants écrivent…", gh_gotit:"correct",
    acc_hint:"Les petites fautes de frappe sont acceptées. La première variante est affichée comme réponse correcte.",
    err_acc:(n)=>`Question ${n} : ajoute au moins une réponse acceptée.`,
    type_not_live:"Les questions à réponse libre fonctionnent pour l'instant en solo/chacun son tour seulement (pas en direct/devoir).",
    tf_true:"Vrai", tf_false:"Faux",
    time_limit:"Temps", pts:"Points", pts_std:"Standard", pts_dbl:"Double",
    sec:"s", save:"Enregistrer le quiz", cancel:"Annuler",
    saved:"Quiz enregistré !", deleted:"Quiz supprimé",
    err_title:"Donne un titre au quiz.", err_noq:"Ajoute au moins une question.",
    err_qtext:(n)=>"La question "+n+" n'a pas de texte.",
    err_ans:(n)=>"La question "+n+" a besoin d'au moins 2 réponses.",
    err_corr:(n)=>"Indique la bonne réponse pour la question "+n+".",
    confirm_del:"Supprimer ce quiz définitivement ?",
    setup_title:"Configurer la partie", choose_mode:"Mode de jeu",
    mode_solo:"Solo", mode_solo_d:"Joue seul et bats ton record.",
    mode_hot:"Chacun son tour", mode_hot_d:"Plusieurs joueurs sur le même appareil.",
    players:"Joueurs", player_ph:"Nom du joueur",
    add_player:"+ Ajouter un joueur", your_name:"Ton nom", you:"Toi",
    start_game:"Commencer", need_player:"Ajoute au moins un joueur.",
    get_ready:"Prépare-toi !", q_of:(a,b)=>"Question "+a+" sur "+b,
    tap_answer:"Touche la bonne réponse", keys_hint:"Appuie sur 1-4 ou touche une réponse",
    pass_to:"Passe l'appareil à", tap_start:"Touche quand tu es prêt",
    ready_q:"Prêt ?", time_up:"Temps écoulé !",
    verdict_ok:"Correct !", verdict_no:"Faux !", verdict_miss:"Pas de réponse",
    correct_was:"Bonne réponse :", pts_earned:"points", streak:(n)=>n+" d'affilée 🔥",
    continue:"Continuer", scoreboard:"Classement",
    podium_title:"Félicitations !", winner_is:"Gagnant",
    play_again:"Rejouer", back_home:"Accueil", final_scores:"Scores finaux",
    stat_correct:"Correctes", stat_acc:"Précision", stat_pts:"Points", stat_streak:"Meilleure série",
    set_title:"Paramètres", set_lang:"Langue", set_lang_d:"Langue de l'interface",
    set_sound:"Son", set_sound_d:"Effets sonores pendant le jeu",
    set_about:"Undava est un jeu à fichier unique, hors ligne et sans commerce. Tes quiz sont stockés uniquement sur cet appareil. Utilise Exporter pour les garder sous forme de fichiers.",
    close:"Fermer",
    import_title:"Importer un quiz", import_drop:"Dépose un fichier .json ici ou touche pour choisir",
    import_paste:"…ou colle le contenu JSON :", import_btn:"Importer",
    import_ok:"Quiz importé !", import_err:"Fichier invalide. Vérifie le format JSON.",
    import_paste_ph:'{"title":"…","questions":[…]}',
    quit_q:"Quitter la partie ? La progression sera perdue.", quit:"Quitter",
    guestbook:"Commentaires",
    fb_title:"Commentaires des visiteurs", fb_sub:"Laisse un mot sur les quiz et vois ce que les autres ont écrit.",
    fb_recent:"Commentaires récents", fb_empty:"Aucun commentaire pour l'instant. Sois le premier !",
    fb_name:"Nom (facultatif)", fb_name_ph:"Comment t'appeler ?",
    fb_quiz:"Quiz (facultatif)", fb_pick_quiz:"— choisis un quiz —",
    fb_rating:"Note", fb_msg:"Message", fb_msg_ph:"Qu'as-tu aimé ? Que pourrait-on améliorer ?",
    fb_send:"Envoyer", fb_anon:"Anonyme",
    fb_need_rating:"Choisis une note (1 à 5 étoiles).", fb_need_msg:"Écris un message.",
    fb_thanks:"Merci pour ton commentaire !", fb_thanks_mod:"Merci ! Ton message apparaîtra après approbation.",
    fb_leave:"Laisser un commentaire", fb_local_note:"(enregistré localement, sur cet appareil)",
    fb_approve:"Approuver", fb_pending:"en attente",
    set_admin:"Administrateur", set_admin_on:"Tu es connecté en tant qu'administrateur.", set_admin_off:"Connecte-toi pour modifier les quiz.",
    login:"Se connecter", logout:"Se déconnecter", password:"Mot de passe", password_again:"Répète le mot de passe",
    admin_login:"Connexion administrateur", admin_login_d:"Saisis le mot de passe pour créer et modifier des quiz.",
    admin_setup:"Définir le mot de passe administrateur", admin_setup_d:"C'est la première visite. Choisis un mot de passe pour l'espace admin.",
    admin_create:"Créer le mot de passe", admin_ok:"Connecté !", admin_out:"Déconnecté.", admin_bad:"Mot de passe incorrect.",
    err_pw:"Saisis le mot de passe.", err_pw_short:"Le mot de passe doit comporter au moins 6 caractères.", err_pw_match:"Les mots de passe ne correspondent pas.",
    net_err:"Erreur réseau. Vérifie ta connexion.",
    live_nav:"En direct",
    live_title:"Session en direct", live_sub:"Nuage de mots, sondages et questions — sur les téléphones du public, en temps réel.",
    live_join_code:"Rejoindre avec un code", live_code_ph:"CODE", live_join:"Rejoindre", live_host_new:"Ou lance une nouvelle activité",
    live_t_cloud:"Nuage de mots", live_t_cloud_d:"Le public envoie des mots, affichés en direct.",
    live_t_poll:"Sondage en direct", live_t_poll_d:"Vote sur des options, barres en temps réel.",
    live_t_qa:"Questions et idées", live_t_qa_d:"Messages du public, avec votes.",
    live_options:"Options", live_option:"Option", live_add_option:"Ajouter une option",
    live_max_words:"Combien de mots une personne peut envoyer",
    live_prompt:"Question / sujet", live_prompt_ph:"Ex. Quel mot décrit le futur ?", live_launch:"Lancer la session",
    live_host_login:"Connecte-toi en tant qu'administrateur pour animer une session.",
    live_need_prompt:"Écris une question ou un sujet.", live_need_opts:"Ajoute au moins 2 options.", live_need_code:"Saisis un code.",
    live_back:"Fermer", live_open:"OUVERT", live_closed_b:"FERMÉ",
    live_pause:"Pause", live_resume:"Reprendre", live_clear:"Effacer", live_clear_q:"Supprimer toutes les réponses ?",
    live_end:"Terminer", live_end_q:"Terminer définitivement la session ? Les données seront supprimées.", live_ended:"Session fermée.",
    live_responses:"réponses", live_join_at:"Rejoins sur", live_copy:"Copier le lien", live_share:"Partager", live_copied:"Lien copié !",
    live_leave:"Quitter", live_loading:"Chargement…", live_notfound:"Session introuvable ou terminée.",
    live_closed_note:"L'animateur a suspendu les réponses.", live_live_results:"Résultats en direct",
    live_word_ph:"Écris un mot…", live_qa_ph:"Écris ta question ou ton idée…",
    live_send:"Envoyer", live_sent:"Envoyé !", live_slow:"Trop vite — attends un instant.", live_closed:"Les réponses sont closes.",
    live_need_text:"Écris quelque chose d'abord.",
    live_words_left:(n)=>`Tu peux encore envoyer ${n} mot(s).`, live_thanks_words:"Merci ! Vois le nuage de mots ci-dessous.",
    live_voted:"Vote compté !", live_waiting:"En attente de réponses…", live_show:"Afficher", live_hide:"Masquer",
    live_png_name:"nuage-de-mots", live_json_name:"resultats-live",
    mode_live:"En direct (multijoueur)", mode_live_d:"Anime sur le grand écran ; le public joue sur téléphone.",
    game_host:"Animer en direct", game_join_note:"Les joueurs rejoignent depuis leur téléphone en scannant le QR code ou en saisissant le code affiché.",
    game_players:"joueurs", game_waiting_players:"En attente de joueurs… partage le code !",
    game_start:"Commencer", game_reveal:"Révéler la réponse", game_next:"Suivant", game_results:"Résultats", game_replay:"Rejouer",
    game_pick_name:"Choisis un nom et un avatar", game_nickname:"Ton pseudo", game_enter:"Entrer dans le jeu", game_need_name:"Saisis un pseudo.",
    game_youre_in:"Tu es dans la partie !", game_look_screen:"Regarde l'écran principal.", game_lobby_closed:"La partie a déjà commencé.",
    game_locked:"Réponse envoyée !", game_wait_others:"En attente des autres joueurs…", game_times_up:"Temps écoulé !",
    game_correct:"Correct !", game_wrong:"Faux", game_missed:"Pas de réponse", game_your_rank:"Ton rang :", game_finished:"Partie terminée !",
    shuffle_opt:"Mélanger questions et réponses", nick_gen:"Pseudo aléatoire",
    team_opt:"Mode équipes", team_word:"équipes", team_pick:"Choisis ton équipe", team_standings:"Classement des équipes",
    spin_nav:"Roue", spin_title:"Roue de la fortune", spin_sub:"Saisis des noms ou des options, puis fais tourner pour choisir au hasard.",
    spin_go:"Tourner", spin_items:"Éléments", spin_ph:"Un élément par ligne…\nAlice\nBob\nCarole", spin_need:"Ajoute au moins 2 éléments.",
    spin_elim:"Retirer le gagnant après chaque tirage", spin_shuffle:"Mélanger", spin_reset:"Réinitialiser", spin_default:"Alice|Bob|Carole|David|Éva|Franck",
    live_filter:"Filtre anti-grossièretés", live_filter_off:"Désactivé", live_filter_on:"Activé", live_filter_hint:"Masque automatiquement les mots vulgaires dans les réponses (les remplace par ***).",
    live_qa_mod:"Modération des questions", live_qa_mod_off:"Désactivée (tout s'affiche)", live_qa_mod_on:"Exiger une approbation",
    live_qa_mod_hint:"Avec approbation, les questions n'apparaissent au public qu'après ton approbation.",
    qa_approve:"Approuver", qa_pending:"En attente", qa_pending_count:(n)=>`${n} en attente`, live_sent_mod:"Envoyé pour approbation.",
    deck_export:"Exporter", deck_import:"Importer", deck_import_empty:"Aucune activité valide dans le fichier.", deck_import_bad:"Fichier invalide.", deck_imported:(n)=>`${n} activités importées.`,
    live_mode_single:"Activité unique", live_mode_deck:"Présentation",
    deck_title:"Titre de la présentation", deck_title_ph:"ex. Atelier Atlantykron — retours",
    deck_slides:"Activités", deck_empty:"Aucune activité. Choisis un type ci-dessus et ajoutes-en une.",
    deck_add_new:"Ajouter une activité", deck_add_slide:"Ajouter à la présentation", deck_added:"Activité ajoutée.",
    deck_launch:"Lancer la présentation", deck_need_slides:"Ajoute au moins une activité.", deck_full:"Maximum 50 activités.",
    deck_default_title:"Présentation", deck_slide:"Activité", deck_prev:"Précédent", deck_next:"Suivant",
    live_t_scale:"Échelles (Likert)", live_t_scale_d:"Plusieurs affirmations notées de 1 à 5 (pas d'accord→d'accord).",
    live_t_points:"100 points", live_t_points_d:"Les participants répartissent un budget de points entre les options.",
    live_statements:"Affirmations", live_statement:"Affirmation", live_add_stmt:"Ajouter une affirmation",
    live_scale_hint:"Chaque affirmation est notée sur une échelle de 1 (pas du tout d'accord) à 5 (tout à fait d'accord).",
    live_points_opts:"Options", live_points_hint:"Chaque participant répartit 100 points entre ces options.",
    live_need_stmts:"Ajoute au moins 2 affirmations.",
    scale_lo:"pas du tout d'accord", scale_hi:"tout à fait d'accord", scale_all:"Note chaque affirmation.",
    pt_remaining:"Restant :", pt_useall:"Utilise tous tes points", pt_budget_each:(n)=>`${n} points chacun`,
    live_t_rating:"Note / NPS", live_t_rating_d:"Note en étoiles ou score NPS 0–10.",
    live_t_rank:"Classement", live_t_rank_d:"Les participants ordonnent les options par préférence.",
    live_rating_scale:"Type d'échelle", live_rank_opts:"Options à classer", rank_submit:"Envoyer le classement",
    rt_avg:"moyenne", rt_prom:"Promoteurs", rt_pass:"Passifs", rt_det:"Détracteurs", rk_foot:"classé par rang moyen (plus bas = meilleur)",
    nps_low:"Pas du tout probable", nps_high:"Extrêmement probable",
    qa_answered:"Répondu", qa_star:"Mettre en avant", qa_mark:"Marquer comme répondu",
    offline_banner:"Tu es hors ligne — solo, bibliothèque et éditeur fonctionnent. Les modes en direct reviennent une fois reconnecté.",
    install_hint:"Installer Undava comme appli", install_btn:"Installer", offline_feature:"Indisponible hors ligne.", online_back:"Tu es de nouveau en ligne.",
    mode_assign:"À son rythme", mode_assign_d:"Attribue un quiz ; chacun le résout seul, à son rythme.",
    assign_host:"Attribuer un devoir", assign_note:"Les participants rejoignent depuis leur téléphone et résolvent à leur rythme. Les résultats s'accumulent ici.",
    assign_intro:(n)=>`${n} questions · à ton rythme`, assign_start:"Commencer",
    assign_done:"terminé", assign_joined:"inscrits", assign_none_done:"Personne n'a encore terminé.",
    assign_closed:"Ce devoir est fermé.", assign_closed_note:"L'animateur a fermé le devoir.",
    assign_complete:"Tu as terminé !", assign_finish:"Voir le résultat",
    game_report:"Rapport", report_title:"Rapport de partie", report_byq:"Par question", report_byp:"Par joueur",
    report_players:"joueurs", report_questions:"questions", report_avg_score:"score moyen", report_accuracy:"précision", report_avg_time:"temps moyen",
    report_correct:"correctes", report_answered:"répondu", report_csv:"CSV", report_json:"JSON", report_print:"Imprimer",
    report_easy:"facile", report_med:"moyen", report_hard:"difficile", report_back:"Retour", report_no_data:"Aucune donnée.", report_player:"Joueur",
  },
  it:{
    tagline:"Quiz senza frontiere",
    home_kicker:"Gioco a quiz • offline • gratuito",
    home_title_1:"Impara, gioca,",
    home_title_2:"vinci",
    home_sub:"Un gioco a quiz nello spirito di Kahoot — senza account, senza pubblicità, senza internet. Crea i tuoi quiz e gioca con gli amici sullo stesso dispositivo.",
    play:"Gioca", create:"Crea quiz", library:"I miei quiz", import:"Importa", settings:"Impostazioni",
    feat_offline:"100% offline", feat_free:"Trade-free", feat_priv:"Zero telemetria", feat_open:"File unico",
    lib_title:"Libreria dei quiz", lib_sub:"Scegli un quiz da giocare o modificare",
    lib_samples:"Quiz di esempio", lib_mine:"I miei quiz",
    lib_empty:"Non hai ancora creato nessun quiz. Tocca « Crea quiz » per iniziare.",
    edit:"Modifica", duplicate:"Duplica", del:"Elimina", export:"Esporta", playbtn:"Gioca",
    q_count:(n)=>n+(n===1?" domanda":" domande"),
    new_quiz:"Nuovo quiz", edit_quiz:"Modifica quiz",
    quiz_title:"Titolo del quiz", quiz_title_ph:"es. Cultura generale",
    quiz_desc:"Descrizione (facoltativa)", quiz_desc_ph:"Una breve descrizione…",
    questions:"Domande", add_question:"Aggiungi domanda",
    question_n:(n)=>"Domanda "+n, question_ph:"Scrivi qui la domanda…",
    answer_ph:"Risposta…", correct:"Corretta", add_answer:"+ Aggiungi risposta",
    help_title:"Guida d'uso", help_sub:"Manuale completo, passo dopo passo.", help_nav:"Guida", help_book_user:"Manuale utente", help_book_admin:"Manuale amministratore",
    qtype:"Tipo", type_quiz:"Scelta multipla (4)", type_tf:"Vero / Falso", type_type:"Risposta libera", type_num:"Numerica (indovina)",
    num_target:"Numero corretto", num_target_ph:"es. 42", num_tol:"Tolleranza accettata",
    num_hint:"Le risposte nell'intervallo ± tolleranza sono corrette; più sei vicino, più punti prendi. Tolleranza 0 = solo esatto.",
    err_num:(n)=>`Domanda ${n}: inserisci un numero valido.`, num_answer_ph:"Scrivi un numero…",
    acc_primary_ph:"La risposta corretta (mostrata)", acc_alt_ph:"Variante accettata (sinonimo, ortografia)", acc_add:"Aggiungi variante accettata",
    type_answer_ph:"Scrivi la risposta…", type_submit:"Invia", type_need_answer:"Scrivi una risposta.", you_wrote:"Hai scritto:",
    gh_typing:"I partecipanti stanno scrivendo…", gh_gotit:"corrette",
    acc_hint:"Sono accettati anche piccoli errori di battitura. La prima variante è mostrata come risposta corretta.",
    err_acc:(n)=>`Domanda ${n}: aggiungi almeno una risposta accettata.`,
    type_not_live:"Le domande a risposta libera per ora funzionano solo in solo/a turni (non dal vivo/compito).",
    tf_true:"Vero", tf_false:"Falso",
    time_limit:"Tempo", pts:"Punti", pts_std:"Standard", pts_dbl:"Doppio",
    sec:"s", save:"Salva quiz", cancel:"Annulla",
    saved:"Quiz salvato!", deleted:"Quiz eliminato",
    err_title:"Dai un titolo al quiz.", err_noq:"Aggiungi almeno una domanda.",
    err_qtext:(n)=>"La domanda "+n+" non ha testo.",
    err_ans:(n)=>"La domanda "+n+" richiede almeno 2 risposte.",
    err_corr:(n)=>"Indica la risposta corretta per la domanda "+n+".",
    confirm_del:"Eliminare definitivamente questo quiz?",
    setup_title:"Imposta la partita", choose_mode:"Modalità di gioco",
    mode_solo:"Solo", mode_solo_d:"Gioca da solo e batti il tuo record.",
    mode_hot:"A turni", mode_hot_d:"Più giocatori sullo stesso dispositivo.",
    players:"Giocatori", player_ph:"Nome del giocatore",
    add_player:"+ Aggiungi giocatore", your_name:"Il tuo nome", you:"Tu",
    start_game:"Inizia partita", need_player:"Aggiungi almeno un giocatore.",
    get_ready:"Preparati!", q_of:(a,b)=>"Domanda "+a+" di "+b,
    tap_answer:"Tocca la risposta corretta", keys_hint:"Premi 1-4 o tocca una risposta",
    pass_to:"Passa il dispositivo a", tap_start:"Tocca quando sei pronto",
    ready_q:"Pronto?", time_up:"Tempo scaduto!",
    verdict_ok:"Corretto!", verdict_no:"Sbagliato!", verdict_miss:"Nessuna risposta",
    correct_was:"Risposta corretta:", pts_earned:"punti", streak:(n)=>n+" di fila 🔥",
    continue:"Continua", scoreboard:"Classifica",
    podium_title:"Congratulazioni!", winner_is:"Vincitore",
    play_again:"Gioca ancora", back_home:"Home", final_scores:"Punteggi finali",
    stat_correct:"Corrette", stat_acc:"Precisione", stat_pts:"Punti", stat_streak:"Serie migliore",
    set_title:"Impostazioni", set_lang:"Lingua", set_lang_d:"Lingua dell'interfaccia",
    set_sound:"Suono", set_sound_d:"Effetti sonori durante il gioco",
    set_about:"Undava è un gioco a file unico, offline e trade-free. I tuoi quiz sono salvati solo su questo dispositivo. Usa Esporta per conservarli come file.",
    close:"Chiudi",
    import_title:"Importa un quiz", import_drop:"Trascina qui un file .json o tocca per scegliere",
    import_paste:"…oppure incolla il contenuto JSON:", import_btn:"Importa",
    import_ok:"Quiz importato!", import_err:"File non valido. Controlla il formato JSON.",
    import_paste_ph:'{"title":"…","questions":[…]}',
    quit_q:"Uscire dalla partita? I progressi andranno persi.", quit:"Esci",
    guestbook:"Feedback",
    fb_title:"Feedback dei visitatori", fb_sub:"Lascia un pensiero sui quiz e leggi cosa hanno scritto gli altri.",
    fb_recent:"Feedback recenti", fb_empty:"Ancora nessun feedback. Sii il primo!",
    fb_name:"Nome (facoltativo)", fb_name_ph:"Come ti chiamiamo?",
    fb_quiz:"Quiz (facoltativo)", fb_pick_quiz:"— scegli un quiz —",
    fb_rating:"Voto", fb_msg:"Messaggio", fb_msg_ph:"Cosa ti è piaciuto? Cosa si può migliorare?",
    fb_send:"Invia feedback", fb_anon:"Anonimo",
    fb_need_rating:"Scegli un voto (1–5 stelle).", fb_need_msg:"Scrivi un messaggio.",
    fb_thanks:"Grazie per il tuo feedback!", fb_thanks_mod:"Grazie! Il tuo messaggio apparirà dopo l'approvazione.",
    fb_leave:"Lascia un feedback", fb_local_note:"(salvato localmente, su questo dispositivo)",
    fb_approve:"Approva", fb_pending:"in attesa",
    set_admin:"Amministratore", set_admin_on:"Hai effettuato l'accesso come amministratore.", set_admin_off:"Accedi per modificare i quiz.",
    login:"Accedi", logout:"Esci", password:"Password", password_again:"Ripeti la password",
    admin_login:"Accesso amministratore", admin_login_d:"Inserisci la password per creare e modificare i quiz.",
    admin_setup:"Imposta la password amministratore", admin_setup_d:"È la prima visita. Scegli una password per l'area admin.",
    admin_create:"Crea password", admin_ok:"Accesso effettuato!", admin_out:"Disconnesso.", admin_bad:"Password errata.",
    err_pw:"Inserisci la password.", err_pw_short:"La password deve avere almeno 6 caratteri.", err_pw_match:"Le password non coincidono.",
    net_err:"Errore di rete. Controlla la connessione.",
    live_nav:"Dal vivo",
    live_title:"Sessione dal vivo", live_sub:"Nuvola di parole, sondaggi e domande — sui telefoni del pubblico, in tempo reale.",
    live_join_code:"Entra con un codice", live_code_ph:"CODICE", live_join:"Entra", live_host_new:"Oppure avvia una nuova attività",
    live_t_cloud:"Nuvola di parole", live_t_cloud_d:"Il pubblico invia parole, mostrate dal vivo.",
    live_t_poll:"Sondaggio dal vivo", live_t_poll_d:"Vota le opzioni, barre in tempo reale.",
    live_t_qa:"Domande e idee", live_t_qa_d:"Messaggi del pubblico, con voti.",
    live_options:"Opzioni", live_option:"Opzione", live_add_option:"Aggiungi opzione",
    live_max_words:"Quante parole può inviare una persona",
    live_prompt:"Domanda / argomento", live_prompt_ph:"Es. Quale parola descrive il futuro?", live_launch:"Avvia sessione",
    live_host_login:"Accedi come amministratore per ospitare una sessione.",
    live_need_prompt:"Scrivi una domanda o un argomento.", live_need_opts:"Aggiungi almeno 2 opzioni.", live_need_code:"Inserisci un codice.",
    live_back:"Chiudi", live_open:"APERTO", live_closed_b:"CHIUSO",
    live_pause:"Pausa", live_resume:"Riprendi", live_clear:"Cancella", live_clear_q:"Eliminare tutte le risposte?",
    live_end:"Termina", live_end_q:"Terminare definitivamente la sessione? I dati verranno eliminati.", live_ended:"Sessione chiusa.",
    live_responses:"risposte", live_join_at:"Entra su", live_copy:"Copia link", live_share:"Condividi", live_copied:"Link copiato!",
    live_leave:"Esci", live_loading:"Caricamento…", live_notfound:"Sessione non trovata o terminata.",
    live_closed_note:"L'organizzatore ha sospeso le risposte.", live_live_results:"Risultati dal vivo",
    live_word_ph:"Scrivi una parola…", live_qa_ph:"Scrivi la tua domanda o idea…",
    live_send:"Invia", live_sent:"Inviato!", live_slow:"Troppo veloce — aspetta un attimo.", live_closed:"Le risposte sono chiuse.",
    live_need_text:"Scrivi prima qualcosa.",
    live_words_left:(n)=>`Puoi inviare ancora ${n} parola/e.`, live_thanks_words:"Grazie! Guarda la nuvola di parole qui sotto.",
    live_voted:"Voto registrato!", live_waiting:"In attesa di risposte…", live_show:"Mostra", live_hide:"Nascondi",
    live_png_name:"nuvola-di-parole", live_json_name:"risultati-live",
    mode_live:"Dal vivo (multigiocatore)", mode_live_d:"Ospita sul grande schermo; il pubblico gioca sui telefoni.",
    game_host:"Ospita dal vivo", game_join_note:"I giocatori entrano dai loro telefoni scansionando il QR code o inserendo il codice mostrato.",
    game_players:"giocatori", game_waiting_players:"In attesa di giocatori… condividi il codice!",
    game_start:"Inizia partita", game_reveal:"Rivela risposta", game_next:"Avanti", game_results:"Risultati", game_replay:"Gioca ancora",
    game_pick_name:"Scegli nome e avatar", game_nickname:"Il tuo nickname", game_enter:"Entra nel gioco", game_need_name:"Inserisci un nickname.",
    game_youre_in:"Sei dentro!", game_look_screen:"Guarda lo schermo principale.", game_lobby_closed:"La partita è già iniziata.",
    game_locked:"Risposta inviata!", game_wait_others:"In attesa degli altri giocatori…", game_times_up:"Tempo scaduto!",
    game_correct:"Corretto!", game_wrong:"Sbagliato", game_missed:"Nessuna risposta", game_your_rank:"La tua posizione:", game_finished:"Partita finita!",
    shuffle_opt:"Mescola domande e risposte", nick_gen:"Nickname casuale",
    team_opt:"Modalità squadre", team_word:"squadre", team_pick:"Scegli la tua squadra", team_standings:"Classifica squadre",
    spin_nav:"Ruota", spin_title:"Ruota della fortuna", spin_sub:"Inserisci nomi o opzioni, poi gira per scegliere a caso.",
    spin_go:"Gira", spin_items:"Elementi", spin_ph:"Un elemento per riga…\nAlice\nBob\nCarla", spin_need:"Aggiungi almeno 2 elementi.",
    spin_elim:"Rimuovi il vincitore dopo ogni giro", spin_shuffle:"Mescola", spin_reset:"Reimposta", spin_default:"Alice|Bob|Carla|Davide|Eva|Franco",
    live_filter:"Filtro volgarità", live_filter_off:"Off", live_filter_on:"On", live_filter_hint:"Maschera automaticamente le parole volgari nelle risposte (le sostituisce con ***).",
    live_qa_mod:"Moderazione domande", live_qa_mod_off:"Off (tutte visibili)", live_qa_mod_on:"Richiedi approvazione",
    live_qa_mod_hint:"Con l'approvazione, le domande appaiono al pubblico solo dopo che le approvi.",
    qa_approve:"Approva", qa_pending:"In attesa", qa_pending_count:(n)=>`${n} in attesa`, live_sent_mod:"Inviato per approvazione.",
    deck_export:"Esporta", deck_import:"Importa", deck_import_empty:"Nessuna attività valida nel file.", deck_import_bad:"File non valido.", deck_imported:(n)=>`${n} attività importate.`,
    live_mode_single:"Attività singola", live_mode_deck:"Presentazione",
    deck_title:"Titolo della presentazione", deck_title_ph:"es. Workshop Atlantykron — feedback",
    deck_slides:"Attività", deck_empty:"Ancora nessuna attività. Scegli un tipo qui sopra e aggiungine una.",
    deck_add_new:"Aggiungi un'attività", deck_add_slide:"Aggiungi alla presentazione", deck_added:"Attività aggiunta.",
    deck_launch:"Avvia presentazione", deck_need_slides:"Aggiungi almeno un'attività.", deck_full:"Massimo 50 attività.",
    deck_default_title:"Presentazione", deck_slide:"Attività", deck_prev:"Precedente", deck_next:"Successivo",
    live_t_scale:"Scale (Likert)", live_t_scale_d:"Più affermazioni valutate da 1 a 5 (disaccordo→accordo).",
    live_t_points:"100 punti", live_t_points_d:"I partecipanti dividono un budget di punti tra le opzioni.",
    live_statements:"Affermazioni", live_statement:"Affermazione", live_add_stmt:"Aggiungi affermazione",
    live_scale_hint:"Ogni affermazione è valutata su una scala da 1 (totale disaccordo) a 5 (totale accordo).",
    live_points_opts:"Opzioni", live_points_hint:"Ogni partecipante divide 100 punti tra queste opzioni.",
    live_need_stmts:"Aggiungi almeno 2 affermazioni.",
    scale_lo:"totale disaccordo", scale_hi:"totale accordo", scale_all:"Valuta ogni affermazione.",
    pt_remaining:"Rimanenti:", pt_useall:"Usa tutti i tuoi punti", pt_budget_each:(n)=>`${n} punti ciascuno`,
    live_t_rating:"Voto / NPS", live_t_rating_d:"Voto a stelle o punteggio NPS 0–10.",
    live_t_rank:"Classifica", live_t_rank_d:"I partecipanti ordinano le opzioni per preferenza.",
    live_rating_scale:"Tipo di scala", live_rank_opts:"Opzioni da ordinare", rank_submit:"Invia classifica",
    rt_avg:"media", rt_prom:"Promotori", rt_pass:"Passivi", rt_det:"Detrattori", rk_foot:"ordinato per posizione media (più basso = meglio)",
    nps_low:"Per niente probabile", nps_high:"Estremamente probabile",
    qa_answered:"Risposto", qa_star:"Evidenzia", qa_mark:"Segna come risposto",
    offline_banner:"Sei offline — solo, libreria ed editor funzionano. Le modalità dal vivo tornano una volta riconnesso.",
    install_hint:"Installa Undava come app", install_btn:"Installa", offline_feature:"Non disponibile offline.", online_back:"Sei di nuovo online.",
    mode_assign:"A proprio ritmo", mode_assign_d:"Assegna un quiz; ognuno lo risolve da solo, al proprio ritmo.",
    assign_host:"Assegna compito", assign_note:"I partecipanti entrano dai loro telefoni e risolvono al proprio ritmo. I risultati si raccolgono qui.",
    assign_intro:(n)=>`${n} domande · al tuo ritmo`, assign_start:"Inizia",
    assign_done:"finito", assign_joined:"iscritti", assign_none_done:"Nessuno ha ancora finito.",
    assign_closed:"Questo compito è chiuso.", assign_closed_note:"L'organizzatore ha chiuso il compito.",
    assign_complete:"Hai finito!", assign_finish:"Vedi il risultato",
    game_report:"Report", report_title:"Report della partita", report_byq:"Per domanda", report_byp:"Per giocatore",
    report_players:"giocatori", report_questions:"domande", report_avg_score:"punteggio medio", report_accuracy:"precisione", report_avg_time:"tempo medio",
    report_correct:"corrette", report_answered:"risposto", report_csv:"CSV", report_json:"JSON", report_print:"Stampa",
    report_easy:"facile", report_med:"media", report_hard:"difficile", report_back:"Indietro", report_no_data:"Ancora nessun dato.", report_player:"Giocatore",
  },
  es:{
    tagline:"Quiz sin fronteras",
    home_kicker:"Juego de preguntas • sin conexión • gratis",
    home_title_1:"Aprende, juega,",
    home_title_2:"gana",
    home_sub:"Un juego de preguntas al estilo de Kahoot — sin cuentas, sin anuncios, sin internet. Crea tus propios cuestionarios y juega con amigos en el mismo dispositivo.",
    play:"Jugar", create:"Crear cuestionario", library:"Mis cuestionarios", import:"Importar", settings:"Ajustes",
    feat_offline:"100% sin conexión", feat_free:"Sin comercio", feat_priv:"Cero telemetría", feat_open:"Archivo único",
    lib_title:"Biblioteca de cuestionarios", lib_sub:"Elige un cuestionario para jugar o editar",
    lib_samples:"Cuestionarios de ejemplo", lib_mine:"Mis cuestionarios",
    lib_empty:"Aún no has creado ningún cuestionario. Toca « Crear cuestionario » para empezar.",
    edit:"Editar", duplicate:"Duplicar", del:"Eliminar", export:"Exportar", playbtn:"Jugar",
    q_count:(n)=>n+(n===1?" pregunta":" preguntas"),
    new_quiz:"Nuevo cuestionario", edit_quiz:"Editar cuestionario",
    quiz_title:"Título del cuestionario", quiz_title_ph:"p. ej. Cultura general",
    quiz_desc:"Descripción (opcional)", quiz_desc_ph:"Una breve descripción…",
    questions:"Preguntas", add_question:"Añadir pregunta",
    question_n:(n)=>"Pregunta "+n, question_ph:"Escribe aquí la pregunta…",
    answer_ph:"Respuesta…", correct:"Correcta", add_answer:"+ Añadir respuesta",
    help_title:"Guía de uso", help_sub:"Manual completo, paso a paso.", help_nav:"Guía", help_book_user:"Manual del usuario", help_book_admin:"Manual del administrador",
    qtype:"Tipo", type_quiz:"Opción múltiple (4)", type_tf:"Verdadero / Falso", type_type:"Respuesta libre", type_num:"Numérica (adivina)",
    num_target:"Número correcto", num_target_ph:"p. ej. 42", num_tol:"Tolerancia aceptada",
    num_hint:"Las respuestas dentro del intervalo ± tolerancia son correctas; cuanto más cerca, más puntos. Tolerancia 0 = solo exacto.",
    err_num:(n)=>`Pregunta ${n}: introduce un número válido.`, num_answer_ph:"Escribe un número…",
    acc_primary_ph:"La respuesta correcta (mostrada)", acc_alt_ph:"Variante aceptada (sinónimo, ortografía)", acc_add:"Añadir variante aceptada",
    type_answer_ph:"Escribe tu respuesta…", type_submit:"Enviar", type_need_answer:"Escribe una respuesta.", you_wrote:"Escribiste:",
    gh_typing:"Los participantes están escribiendo…", gh_gotit:"correctas",
    acc_hint:"También se aceptan pequeños errores de tipeo. La primera variante se muestra como respuesta correcta.",
    err_acc:(n)=>`Pregunta ${n}: añade al menos una respuesta aceptada.`,
    type_not_live:"Las preguntas de respuesta libre por ahora funcionan solo en solo/por turnos (no en directo/tarea).",
    tf_true:"Verdadero", tf_false:"Falso",
    time_limit:"Tiempo", pts:"Puntos", pts_std:"Estándar", pts_dbl:"Doble",
    sec:"s", save:"Guardar cuestionario", cancel:"Cancelar",
    saved:"¡Cuestionario guardado!", deleted:"Cuestionario eliminado",
    err_title:"Ponle un título al cuestionario.", err_noq:"Añade al menos una pregunta.",
    err_qtext:(n)=>"La pregunta "+n+" no tiene texto.",
    err_ans:(n)=>"La pregunta "+n+" necesita al menos 2 respuestas.",
    err_corr:(n)=>"Marca la respuesta correcta de la pregunta "+n+".",
    confirm_del:"¿Eliminar este cuestionario para siempre?",
    setup_title:"Configurar la partida", choose_mode:"Modo de juego",
    mode_solo:"Solo", mode_solo_d:"Juega solo y supera tu récord.",
    mode_hot:"Por turnos", mode_hot_d:"Varios jugadores en el mismo dispositivo.",
    players:"Jugadores", player_ph:"Nombre del jugador",
    add_player:"+ Añadir jugador", your_name:"Tu nombre", you:"Tú",
    start_game:"Empezar partida", need_player:"Añade al menos un jugador.",
    get_ready:"¡Prepárate!", q_of:(a,b)=>"Pregunta "+a+" de "+b,
    tap_answer:"Toca la respuesta correcta", keys_hint:"Pulsa 1-4 o toca una respuesta",
    pass_to:"Pasa el dispositivo a", tap_start:"Toca cuando estés listo",
    ready_q:"¿Listo?", time_up:"¡Se acabó el tiempo!",
    verdict_ok:"¡Correcto!", verdict_no:"¡Incorrecto!", verdict_miss:"Sin respuesta",
    correct_was:"Respuesta correcta:", pts_earned:"puntos", streak:(n)=>n+" seguidas 🔥",
    continue:"Continuar", scoreboard:"Clasificación",
    podium_title:"¡Felicidades!", winner_is:"Ganador",
    play_again:"Jugar de nuevo", back_home:"Inicio", final_scores:"Puntuaciones finales",
    stat_correct:"Correctas", stat_acc:"Precisión", stat_pts:"Puntos", stat_streak:"Mejor racha",
    set_title:"Ajustes", set_lang:"Idioma", set_lang_d:"Idioma de la interfaz",
    set_sound:"Sonido", set_sound_d:"Efectos de sonido durante el juego",
    set_about:"Undava es un juego de archivo único, sin conexión y sin comercio. Tus cuestionarios se guardan solo en este dispositivo. Usa Exportar para conservarlos como archivos.",
    close:"Cerrar",
    import_title:"Importar un cuestionario", import_drop:"Suelta aquí un archivo .json o toca para elegir",
    import_paste:"…o pega el contenido JSON:", import_btn:"Importar",
    import_ok:"¡Cuestionario importado!", import_err:"Archivo no válido. Comprueba el formato JSON.",
    import_paste_ph:'{"title":"…","questions":[…]}',
    quit_q:"¿Salir de la partida? Se perderá el progreso.", quit:"Salir",
    guestbook:"Comentarios",
    fb_title:"Comentarios de los visitantes", fb_sub:"Deja una opinión sobre los cuestionarios y mira lo que escribieron otros.",
    fb_recent:"Comentarios recientes", fb_empty:"Aún no hay comentarios. ¡Sé el primero!",
    fb_name:"Nombre (opcional)", fb_name_ph:"¿Cómo te llamamos?",
    fb_quiz:"Cuestionario (opcional)", fb_pick_quiz:"— elige un cuestionario —",
    fb_rating:"Valoración", fb_msg:"Mensaje", fb_msg_ph:"¿Qué te gustó? ¿Qué se podría mejorar?",
    fb_send:"Enviar comentario", fb_anon:"Anónimo",
    fb_need_rating:"Elige una valoración (1–5 estrellas).", fb_need_msg:"Escribe un mensaje.",
    fb_thanks:"¡Gracias por tu comentario!", fb_thanks_mod:"¡Gracias! Tu mensaje aparecerá tras la aprobación.",
    fb_leave:"Dejar un comentario", fb_local_note:"(guardado localmente, en este dispositivo)",
    fb_approve:"Aprobar", fb_pending:"pendiente",
    set_admin:"Administrador", set_admin_on:"Has iniciado sesión como administrador.", set_admin_off:"Inicia sesión para editar cuestionarios.",
    login:"Iniciar sesión", logout:"Cerrar sesión", password:"Contraseña", password_again:"Repite la contraseña",
    admin_login:"Inicio de sesión de administrador", admin_login_d:"Introduce la contraseña para crear y editar cuestionarios.",
    admin_setup:"Establecer contraseña de administrador", admin_setup_d:"Es la primera visita. Elige una contraseña para el área de admin.",
    admin_create:"Crear contraseña", admin_ok:"¡Sesión iniciada!", admin_out:"Sesión cerrada.", admin_bad:"Contraseña incorrecta.",
    err_pw:"Introduce la contraseña.", err_pw_short:"La contraseña debe tener al menos 6 caracteres.", err_pw_match:"Las contraseñas no coinciden.",
    net_err:"Error de red. Comprueba tu conexión.",
    live_nav:"En directo",
    live_title:"Sesión en directo", live_sub:"Nube de palabras, encuestas y preguntas — en los móviles del público, en tiempo real.",
    live_join_code:"Únete con un código", live_code_ph:"CÓDIGO", live_join:"Unirse", live_host_new:"O inicia una nueva actividad",
    live_t_cloud:"Nube de palabras", live_t_cloud_d:"El público envía palabras, mostradas en directo.",
    live_t_poll:"Encuesta en directo", live_t_poll_d:"Vota opciones, barras en tiempo real.",
    live_t_qa:"Preguntas e ideas", live_t_qa_d:"Mensajes del público, con votos.",
    live_options:"Opciones", live_option:"Opción", live_add_option:"Añadir opción",
    live_max_words:"Cuántas palabras puede enviar una persona",
    live_prompt:"Pregunta / tema", live_prompt_ph:"P. ej. ¿Qué palabra describe el futuro?", live_launch:"Iniciar sesión",
    live_host_login:"Inicia sesión como administrador para dirigir una sesión.",
    live_need_prompt:"Escribe una pregunta o un tema.", live_need_opts:"Añade al menos 2 opciones.", live_need_code:"Introduce un código.",
    live_back:"Cerrar", live_open:"ABIERTA", live_closed_b:"CERRADA",
    live_pause:"Pausar", live_resume:"Reanudar", live_clear:"Borrar", live_clear_q:"¿Eliminar todas las respuestas?",
    live_end:"Finalizar", live_end_q:"¿Finalizar la sesión para siempre? Se eliminarán los datos.", live_ended:"Sesión cerrada.",
    live_responses:"respuestas", live_join_at:"Únete en", live_copy:"Copiar enlace", live_share:"Compartir", live_copied:"¡Enlace copiado!",
    live_leave:"Salir", live_loading:"Cargando…", live_notfound:"Sesión no encontrada o finalizada.",
    live_closed_note:"El anfitrión pausó las respuestas.", live_live_results:"Resultados en directo",
    live_word_ph:"Escribe una palabra…", live_qa_ph:"Escribe tu pregunta o idea…",
    live_send:"Enviar", live_sent:"¡Enviado!", live_slow:"Demasiado rápido — espera un momento.", live_closed:"Las respuestas están cerradas.",
    live_need_text:"Escribe algo primero.",
    live_words_left:(n)=>`Puedes enviar ${n} palabra(s) más.`, live_thanks_words:"¡Gracias! Mira la nube de palabras abajo.",
    live_voted:"¡Voto contado!", live_waiting:"Esperando respuestas…", live_show:"Mostrar", live_hide:"Ocultar",
    live_png_name:"nube-de-palabras", live_json_name:"resultados-live",
    mode_live:"En directo (multijugador)", mode_live_d:"Dirige en la pantalla grande; el público juega en móviles.",
    game_host:"Dirigir en directo", game_join_note:"Los jugadores entran desde sus móviles escaneando el código QR o introduciendo el código mostrado.",
    game_players:"jugadores", game_waiting_players:"Esperando jugadores… ¡comparte el código!",
    game_start:"Empezar partida", game_reveal:"Revelar respuesta", game_next:"Siguiente", game_results:"Resultados", game_replay:"Jugar de nuevo",
    game_pick_name:"Elige nombre y avatar", game_nickname:"Tu apodo", game_enter:"Entrar al juego", game_need_name:"Introduce un apodo.",
    game_youre_in:"¡Estás dentro!", game_look_screen:"Mira la pantalla principal.", game_lobby_closed:"La partida ya empezó.",
    game_locked:"¡Respuesta enviada!", game_wait_others:"Esperando a los demás jugadores…", game_times_up:"¡Se acabó el tiempo!",
    game_correct:"¡Correcto!", game_wrong:"Incorrecto", game_missed:"Sin respuesta", game_your_rank:"Tu puesto:", game_finished:"¡Partida terminada!",
    shuffle_opt:"Mezclar preguntas y respuestas", nick_gen:"Apodo aleatorio",
    team_opt:"Modo equipos", team_word:"equipos", team_pick:"Elige tu equipo", team_standings:"Clasificación por equipos",
    spin_nav:"Ruleta", spin_title:"Ruleta de la fortuna", spin_sub:"Introduce nombres u opciones y gira para elegir al azar.",
    spin_go:"Girar", spin_items:"Elementos", spin_ph:"Un elemento por línea…\nAna\nBeto\nCarla", spin_need:"Añade al menos 2 elementos.",
    spin_elim:"Quitar al ganador tras cada giro", spin_shuffle:"Mezclar", spin_reset:"Reiniciar", spin_default:"Ana|Beto|Carla|David|Eva|Fran",
    live_filter:"Filtro de palabrotas", live_filter_off:"Off", live_filter_on:"On", live_filter_hint:"Enmascara automáticamente las palabras vulgares en las respuestas (las reemplaza por ***).",
    live_qa_mod:"Moderación de preguntas", live_qa_mod_off:"Off (se muestran todas)", live_qa_mod_on:"Requerir aprobación",
    live_qa_mod_hint:"Con aprobación, las preguntas aparecen al público solo después de que las apruebes.",
    qa_approve:"Aprobar", qa_pending:"Pendiente", qa_pending_count:(n)=>`${n} pendientes`, live_sent_mod:"Enviado para aprobación.",
    deck_export:"Exportar", deck_import:"Importar", deck_import_empty:"No hay actividades válidas en el archivo.", deck_import_bad:"Archivo no válido.", deck_imported:(n)=>`${n} actividades importadas.`,
    live_mode_single:"Actividad única", live_mode_deck:"Presentación",
    deck_title:"Título de la presentación", deck_title_ph:"p. ej. Taller Atlantykron — opiniones",
    deck_slides:"Actividades", deck_empty:"Aún no hay actividades. Elige un tipo arriba y añade una.",
    deck_add_new:"Añadir una actividad", deck_add_slide:"Añadir a la presentación", deck_added:"Actividad añadida.",
    deck_launch:"Iniciar presentación", deck_need_slides:"Añade al menos una actividad.", deck_full:"Máximo 50 actividades.",
    deck_default_title:"Presentación", deck_slide:"Actividad", deck_prev:"Anterior", deck_next:"Siguiente",
    live_t_scale:"Escalas (Likert)", live_t_scale_d:"Varias afirmaciones valoradas de 1 a 5 (desacuerdo→acuerdo).",
    live_t_points:"100 puntos", live_t_points_d:"Los participantes reparten un presupuesto de puntos entre las opciones.",
    live_statements:"Afirmaciones", live_statement:"Afirmación", live_add_stmt:"Añadir afirmación",
    live_scale_hint:"Cada afirmación se valora en una escala de 1 (muy en desacuerdo) a 5 (muy de acuerdo).",
    live_points_opts:"Opciones", live_points_hint:"Cada participante reparte 100 puntos entre estas opciones.",
    live_need_stmts:"Añade al menos 2 afirmaciones.",
    scale_lo:"muy en desacuerdo", scale_hi:"muy de acuerdo", scale_all:"Valora cada afirmación.",
    pt_remaining:"Restantes:", pt_useall:"Usa todos tus puntos", pt_budget_each:(n)=>`${n} puntos cada uno`,
    live_t_rating:"Valoración / NPS", live_t_rating_d:"Valoración por estrellas o puntuación NPS 0–10.",
    live_t_rank:"Ranking", live_t_rank_d:"Los participantes ordenan las opciones por preferencia.",
    live_rating_scale:"Tipo de escala", live_rank_opts:"Opciones para ordenar", rank_submit:"Enviar ranking",
    rt_avg:"media", rt_prom:"Promotores", rt_pass:"Pasivos", rt_det:"Detractores", rk_foot:"ordenado por puesto medio (menor = mejor)",
    nps_low:"Nada probable", nps_high:"Extremadamente probable",
    qa_answered:"Respondida", qa_star:"Destacar", qa_mark:"Marcar como respondida",
    offline_banner:"Estás sin conexión — solo, biblioteca y editor funcionan. Los modos en directo vuelven al reconectar.",
    install_hint:"Instalar Undava como app", install_btn:"Instalar", offline_feature:"No disponible sin conexión.", online_back:"Vuelves a estar en línea.",
    mode_assign:"A tu ritmo", mode_assign_d:"Asigna un cuestionario; cada uno lo resuelve por su cuenta, a su ritmo.",
    assign_host:"Asignar tarea", assign_note:"Los participantes entran desde sus móviles y resuelven a su ritmo. Los resultados se recogen aquí.",
    assign_intro:(n)=>`${n} preguntas · a tu ritmo`, assign_start:"Empezar",
    assign_done:"terminado", assign_joined:"inscritos", assign_none_done:"Nadie ha terminado aún.",
    assign_closed:"Esta tarea está cerrada.", assign_closed_note:"El anfitrión cerró la tarea.",
    assign_complete:"¡Has terminado!", assign_finish:"Ver resultado",
    game_report:"Informe", report_title:"Informe de la partida", report_byq:"Por pregunta", report_byp:"Por jugador",
    report_players:"jugadores", report_questions:"preguntas", report_avg_score:"puntuación media", report_accuracy:"precisión", report_avg_time:"tiempo medio",
    report_correct:"correctas", report_answered:"respondido", report_csv:"CSV", report_json:"JSON", report_print:"Imprimir",
    report_easy:"fácil", report_med:"media", report_hard:"difícil", report_back:"Atrás", report_no_data:"Aún no hay datos.", report_player:"Jugador",
  },
  pt:{
    tagline:"Quiz sem fronteiras",
    home_kicker:"Jogo de perguntas • offline • gratuito",
    home_title_1:"Aprende, joga,",
    home_title_2:"ganha",
    home_sub:"Um jogo de perguntas ao estilo do Kahoot — sem contas, sem anúncios, sem internet. Cria os teus próprios questionários e joga com amigos no mesmo dispositivo.",
    play:"Jogar", create:"Criar questionário", library:"Os meus questionários", import:"Importar", settings:"Definições",
    feat_offline:"100% offline", feat_free:"Sem comércio", feat_priv:"Zero telemetria", feat_open:"Ficheiro único",
    lib_title:"Biblioteca de questionários", lib_sub:"Escolhe um questionário para jogar ou editar",
    lib_samples:"Questionários de exemplo", lib_mine:"Os meus questionários",
    lib_empty:"Ainda não criaste nenhum questionário. Toca em « Criar questionário » para começar.",
    edit:"Editar", duplicate:"Duplicar", del:"Eliminar", export:"Exportar", playbtn:"Jogar",
    q_count:(n)=>n+(n===1?" pergunta":" perguntas"),
    new_quiz:"Novo questionário", edit_quiz:"Editar questionário",
    quiz_title:"Título do questionário", quiz_title_ph:"ex. Cultura geral",
    quiz_desc:"Descrição (opcional)", quiz_desc_ph:"Uma breve descrição…",
    questions:"Perguntas", add_question:"Adicionar pergunta",
    question_n:(n)=>"Pergunta "+n, question_ph:"Escreve aqui a pergunta…",
    answer_ph:"Resposta…", correct:"Correta", add_answer:"+ Adicionar resposta",
    help_title:"Guia de utilização", help_sub:"Manual completo, passo a passo.", help_nav:"Guia", help_book_user:"Manual do utilizador", help_book_admin:"Manual do administrador",
    qtype:"Tipo", type_quiz:"Escolha múltipla (4)", type_tf:"Verdadeiro / Falso", type_type:"Resposta livre", type_num:"Numérica (adivinha)",
    num_target:"Número correto", num_target_ph:"ex. 42", num_tol:"Tolerância aceite",
    num_hint:"As respostas dentro do intervalo ± tolerância estão corretas; quanto mais perto, mais pontos. Tolerância 0 = só exato.",
    err_num:(n)=>`Pergunta ${n}: introduz um número válido.`, num_answer_ph:"Escreve um número…",
    acc_primary_ph:"A resposta correta (mostrada)", acc_alt_ph:"Variante aceite (sinónimo, ortografia)", acc_add:"Adicionar variante aceite",
    type_answer_ph:"Escreve a tua resposta…", type_submit:"Enviar", type_need_answer:"Escreve uma resposta.", you_wrote:"Escreveste:",
    gh_typing:"Os participantes estão a escrever…", gh_gotit:"corretas",
    acc_hint:"Também são aceites pequenos erros de escrita. A primeira variante é mostrada como resposta correta.",
    err_acc:(n)=>`Pergunta ${n}: adiciona pelo menos uma resposta aceite.`,
    type_not_live:"As perguntas de resposta livre por agora funcionam só em solo/à vez (não ao vivo/trabalho).",
    tf_true:"Verdadeiro", tf_false:"Falso",
    time_limit:"Tempo", pts:"Pontos", pts_std:"Padrão", pts_dbl:"Duplo",
    sec:"s", save:"Guardar questionário", cancel:"Cancelar",
    saved:"Questionário guardado!", deleted:"Questionário eliminado",
    err_title:"Dá um título ao questionário.", err_noq:"Adiciona pelo menos uma pergunta.",
    err_qtext:(n)=>"A pergunta "+n+" não tem texto.",
    err_ans:(n)=>"A pergunta "+n+" precisa de pelo menos 2 respostas.",
    err_corr:(n)=>"Marca a resposta correta da pergunta "+n+".",
    confirm_del:"Eliminar este questionário para sempre?",
    setup_title:"Configurar o jogo", choose_mode:"Modo de jogo",
    mode_solo:"Solo", mode_solo_d:"Joga sozinho e bate o teu recorde.",
    mode_hot:"À vez", mode_hot_d:"Vários jogadores no mesmo dispositivo.",
    players:"Jogadores", player_ph:"Nome do jogador",
    add_player:"+ Adicionar jogador", your_name:"O teu nome", you:"Tu",
    start_game:"Começar jogo", need_player:"Adiciona pelo menos um jogador.",
    get_ready:"Prepara-te!", q_of:(a,b)=>"Pergunta "+a+" de "+b,
    tap_answer:"Toca na resposta correta", keys_hint:"Prime 1-4 ou toca numa resposta",
    pass_to:"Passa o dispositivo a", tap_start:"Toca quando estiveres pronto",
    ready_q:"Pronto?", time_up:"Tempo esgotado!",
    verdict_ok:"Correto!", verdict_no:"Errado!", verdict_miss:"Sem resposta",
    correct_was:"Resposta correta:", pts_earned:"pontos", streak:(n)=>n+" seguidas 🔥",
    continue:"Continuar", scoreboard:"Classificação",
    podium_title:"Parabéns!", winner_is:"Vencedor",
    play_again:"Jogar de novo", back_home:"Início", final_scores:"Pontuações finais",
    stat_correct:"Corretas", stat_acc:"Precisão", stat_pts:"Pontos", stat_streak:"Melhor sequência",
    set_title:"Definições", set_lang:"Idioma", set_lang_d:"Idioma da interface",
    set_sound:"Som", set_sound_d:"Efeitos sonoros durante o jogo",
    set_about:"O Undava é um jogo de ficheiro único, offline e sem comércio. Os teus questionários são guardados apenas neste dispositivo. Usa Exportar para os guardar como ficheiros.",
    close:"Fechar",
    import_title:"Importar um questionário", import_drop:"Larga aqui um ficheiro .json ou toca para escolher",
    import_paste:"…ou cola o conteúdo JSON:", import_btn:"Importar",
    import_ok:"Questionário importado!", import_err:"Ficheiro inválido. Verifica o formato JSON.",
    import_paste_ph:'{"title":"…","questions":[…]}',
    quit_q:"Sair do jogo? O progresso será perdido.", quit:"Sair",
    guestbook:"Comentários",
    fb_title:"Comentários dos visitantes", fb_sub:"Deixa uma opinião sobre os questionários e vê o que os outros escreveram.",
    fb_recent:"Comentários recentes", fb_empty:"Ainda não há comentários. Sê o primeiro!",
    fb_name:"Nome (opcional)", fb_name_ph:"Como te chamamos?",
    fb_quiz:"Questionário (opcional)", fb_pick_quiz:"— escolhe um questionário —",
    fb_rating:"Avaliação", fb_msg:"Mensagem", fb_msg_ph:"Do que gostaste? O que se poderia melhorar?",
    fb_send:"Enviar comentário", fb_anon:"Anónimo",
    fb_need_rating:"Escolhe uma avaliação (1–5 estrelas).", fb_need_msg:"Escreve uma mensagem.",
    fb_thanks:"Obrigado pelo teu comentário!", fb_thanks_mod:"Obrigado! A tua mensagem aparecerá após aprovação.",
    fb_leave:"Deixar um comentário", fb_local_note:"(guardado localmente, neste dispositivo)",
    fb_approve:"Aprovar", fb_pending:"pendente",
    set_admin:"Administrador", set_admin_on:"Tens sessão iniciada como administrador.", set_admin_off:"Inicia sessão para editar questionários.",
    login:"Iniciar sessão", logout:"Terminar sessão", password:"Palavra-passe", password_again:"Repete a palavra-passe",
    admin_login:"Início de sessão de administrador", admin_login_d:"Introduz a palavra-passe para criar e editar questionários.",
    admin_setup:"Definir palavra-passe de administrador", admin_setup_d:"É a primeira visita. Escolhe uma palavra-passe para a área de admin.",
    admin_create:"Criar palavra-passe", admin_ok:"Sessão iniciada!", admin_out:"Sessão terminada.", admin_bad:"Palavra-passe incorreta.",
    err_pw:"Introduz a palavra-passe.", err_pw_short:"A palavra-passe deve ter pelo menos 6 caracteres.", err_pw_match:"As palavras-passe não coincidem.",
    net_err:"Erro de rede. Verifica a tua ligação.",
    live_nav:"Ao vivo",
    live_title:"Sessão ao vivo", live_sub:"Nuvem de palavras, sondagens e perguntas — nos telemóveis do público, em tempo real.",
    live_join_code:"Entra com um código", live_code_ph:"CÓDIGO", live_join:"Entrar", live_host_new:"Ou inicia uma nova atividade",
    live_t_cloud:"Nuvem de palavras", live_t_cloud_d:"O público envia palavras, mostradas ao vivo.",
    live_t_poll:"Sondagem ao vivo", live_t_poll_d:"Vota opções, barras em tempo real.",
    live_t_qa:"Perguntas e ideias", live_t_qa_d:"Mensagens do público, com votos.",
    live_options:"Opções", live_option:"Opção", live_add_option:"Adicionar opção",
    live_max_words:"Quantas palavras uma pessoa pode enviar",
    live_prompt:"Pergunta / tema", live_prompt_ph:"Ex. Que palavra descreve o futuro?", live_launch:"Iniciar sessão",
    live_host_login:"Inicia sessão como administrador para dinamizar uma sessão.",
    live_need_prompt:"Escreve uma pergunta ou um tema.", live_need_opts:"Adiciona pelo menos 2 opções.", live_need_code:"Introduz um código.",
    live_back:"Fechar", live_open:"ABERTA", live_closed_b:"FECHADA",
    live_pause:"Pausar", live_resume:"Retomar", live_clear:"Limpar", live_clear_q:"Eliminar todas as respostas?",
    live_end:"Terminar", live_end_q:"Terminar a sessão para sempre? Os dados serão eliminados.", live_ended:"Sessão fechada.",
    live_responses:"respostas", live_join_at:"Entra em", live_copy:"Copiar ligação", live_share:"Partilhar", live_copied:"Ligação copiada!",
    live_leave:"Sair", live_loading:"A carregar…", live_notfound:"Sessão não encontrada ou terminada.",
    live_closed_note:"O anfitrião suspendeu as respostas.", live_live_results:"Resultados ao vivo",
    live_word_ph:"Escreve uma palavra…", live_qa_ph:"Escreve a tua pergunta ou ideia…",
    live_send:"Enviar", live_sent:"Enviado!", live_slow:"Demasiado rápido — espera um momento.", live_closed:"As respostas estão fechadas.",
    live_need_text:"Escreve algo primeiro.",
    live_words_left:(n)=>`Podes enviar mais ${n} palavra(s).`, live_thanks_words:"Obrigado! Vê a nuvem de palavras abaixo.",
    live_voted:"Voto contado!", live_waiting:"À espera de respostas…", live_show:"Mostrar", live_hide:"Ocultar",
    live_png_name:"nuvem-de-palavras", live_json_name:"resultados-live",
    mode_live:"Ao vivo (multijogador)", mode_live_d:"Dinamiza no ecrã grande; o público joga nos telemóveis.",
    game_host:"Dinamizar ao vivo", game_join_note:"Os jogadores entram pelos telemóveis lendo o código QR ou introduzindo o código mostrado.",
    game_players:"jogadores", game_waiting_players:"À espera de jogadores… partilha o código!",
    game_start:"Começar jogo", game_reveal:"Revelar resposta", game_next:"Seguinte", game_results:"Resultados", game_replay:"Jogar de novo",
    game_pick_name:"Escolhe nome e avatar", game_nickname:"A tua alcunha", game_enter:"Entrar no jogo", game_need_name:"Introduz uma alcunha.",
    game_youre_in:"Estás dentro!", game_look_screen:"Olha para o ecrã principal.", game_lobby_closed:"O jogo já começou.",
    game_locked:"Resposta enviada!", game_wait_others:"À espera dos outros jogadores…", game_times_up:"Tempo esgotado!",
    game_correct:"Correto!", game_wrong:"Errado", game_missed:"Sem resposta", game_your_rank:"A tua posição:", game_finished:"Jogo terminado!",
    shuffle_opt:"Baralhar perguntas e respostas", nick_gen:"Alcunha aleatória",
    team_opt:"Modo equipas", team_word:"equipas", team_pick:"Escolhe a tua equipa", team_standings:"Classificação por equipas",
    spin_nav:"Roda", spin_title:"Roda da sorte", spin_sub:"Introduz nomes ou opções e roda para escolher ao acaso.",
    spin_go:"Rodar", spin_items:"Elementos", spin_ph:"Um elemento por linha…\nAna\nBeto\nCarla", spin_need:"Adiciona pelo menos 2 elementos.",
    spin_elim:"Remover o vencedor após cada rodada", spin_shuffle:"Baralhar", spin_reset:"Repor", spin_default:"Ana|Beto|Carla|David|Eva|Rui",
    live_filter:"Filtro de palavrões", live_filter_off:"Off", live_filter_on:"On", live_filter_hint:"Mascara automaticamente as palavras vulgares nas respostas (substitui-as por ***).",
    live_qa_mod:"Moderação de perguntas", live_qa_mod_off:"Off (todas visíveis)", live_qa_mod_on:"Exigir aprovação",
    live_qa_mod_hint:"Com aprovação, as perguntas aparecem ao público só depois de as aprovares.",
    qa_approve:"Aprovar", qa_pending:"Pendente", qa_pending_count:(n)=>`${n} pendentes`, live_sent_mod:"Enviado para aprovação.",
    deck_export:"Exportar", deck_import:"Importar", deck_import_empty:"Nenhuma atividade válida no ficheiro.", deck_import_bad:"Ficheiro inválido.", deck_imported:(n)=>`${n} atividades importadas.`,
    live_mode_single:"Atividade única", live_mode_deck:"Apresentação",
    deck_title:"Título da apresentação", deck_title_ph:"ex. Workshop Atlantykron — comentários",
    deck_slides:"Atividades", deck_empty:"Ainda não há atividades. Escolhe um tipo acima e adiciona uma.",
    deck_add_new:"Adicionar uma atividade", deck_add_slide:"Adicionar à apresentação", deck_added:"Atividade adicionada.",
    deck_launch:"Iniciar apresentação", deck_need_slides:"Adiciona pelo menos uma atividade.", deck_full:"Máximo 50 atividades.",
    deck_default_title:"Apresentação", deck_slide:"Atividade", deck_prev:"Anterior", deck_next:"Seguinte",
    live_t_scale:"Escalas (Likert)", live_t_scale_d:"Várias afirmações avaliadas de 1 a 5 (desacordo→acordo).",
    live_t_points:"100 pontos", live_t_points_d:"Os participantes dividem um orçamento de pontos pelas opções.",
    live_statements:"Afirmações", live_statement:"Afirmação", live_add_stmt:"Adicionar afirmação",
    live_scale_hint:"Cada afirmação é avaliada numa escala de 1 (discordo totalmente) a 5 (concordo totalmente).",
    live_points_opts:"Opções", live_points_hint:"Cada participante divide 100 pontos por estas opções.",
    live_need_stmts:"Adiciona pelo menos 2 afirmações.",
    scale_lo:"discordo totalmente", scale_hi:"concordo totalmente", scale_all:"Avalia cada afirmação.",
    pt_remaining:"Restantes:", pt_useall:"Usa todos os teus pontos", pt_budget_each:(n)=>`${n} pontos cada`,
    live_t_rating:"Avaliação / NPS", live_t_rating_d:"Avaliação por estrelas ou pontuação NPS 0–10.",
    live_t_rank:"Ranking", live_t_rank_d:"Os participantes ordenam as opções por preferência.",
    live_rating_scale:"Tipo de escala", live_rank_opts:"Opções para ordenar", rank_submit:"Enviar ranking",
    rt_avg:"média", rt_prom:"Promotores", rt_pass:"Passivos", rt_det:"Detratores", rk_foot:"ordenado por posição média (menor = melhor)",
    nps_low:"Nada provável", nps_high:"Extremamente provável",
    qa_answered:"Respondida", qa_star:"Destacar", qa_mark:"Marcar como respondida",
    offline_banner:"Estás offline — solo, biblioteca e editor funcionam. Os modos ao vivo voltam ao reconectar.",
    install_hint:"Instalar o Undava como app", install_btn:"Instalar", offline_feature:"Indisponível offline.", online_back:"Estás de novo online.",
    mode_assign:"Ao teu ritmo", mode_assign_d:"Atribui um questionário; cada um resolve sozinho, ao seu ritmo.",
    assign_host:"Atribuir trabalho", assign_note:"Os participantes entram pelos telemóveis e resolvem ao seu ritmo. Os resultados juntam-se aqui.",
    assign_intro:(n)=>`${n} perguntas · ao teu ritmo`, assign_start:"Começar",
    assign_done:"terminado", assign_joined:"inscritos", assign_none_done:"Ainda ninguém terminou.",
    assign_closed:"Este trabalho está fechado.", assign_closed_note:"O anfitrião fechou o trabalho.",
    assign_complete:"Terminaste!", assign_finish:"Ver resultado",
    game_report:"Relatório", report_title:"Relatório do jogo", report_byq:"Por pergunta", report_byp:"Por jogador",
    report_players:"jogadores", report_questions:"perguntas", report_avg_score:"pontuação média", report_accuracy:"precisão", report_avg_time:"tempo médio",
    report_correct:"corretas", report_answered:"respondido", report_csv:"CSV", report_json:"JSON", report_print:"Imprimir",
    report_easy:"fácil", report_med:"média", report_hard:"difícil", report_back:"Voltar", report_no_data:"Ainda não há dados.", report_player:"Jogador",
  },
  de:{
    tagline:"Quiz ohne Grenzen",
    home_kicker:"Quizspiel • offline • kostenlos",
    home_title_1:"Lernen, spielen,",
    home_title_2:"gewinnen",
    home_sub:"Ein Quizspiel im Geiste von Kahoot — ohne Konten, ohne Werbung, ohne Internet. Erstelle eigene Quiz und spiele mit Freunden auf demselben Gerät.",
    play:"Spielen", create:"Quiz erstellen", library:"Meine Quiz", import:"Importieren", settings:"Einstellungen",
    feat_offline:"100% offline", feat_free:"Handelsfrei", feat_priv:"Keine Telemetrie", feat_open:"Eine Datei",
    lib_title:"Quiz-Bibliothek", lib_sub:"Wähle ein Quiz zum Spielen oder Bearbeiten",
    lib_samples:"Beispiel-Quiz", lib_mine:"Meine Quiz",
    lib_empty:"Du hast noch keine Quiz erstellt. Tippe auf „Quiz erstellen“, um zu beginnen.",
    edit:"Bearbeiten", duplicate:"Duplizieren", del:"Löschen", export:"Exportieren", playbtn:"Spielen",
    q_count:(n)=>n+(n===1?" Frage":" Fragen"),
    new_quiz:"Neues Quiz", edit_quiz:"Quiz bearbeiten",
    quiz_title:"Quiz-Titel", quiz_title_ph:"z. B. Allgemeinwissen",
    quiz_desc:"Beschreibung (optional)", quiz_desc_ph:"Eine kurze Beschreibung…",
    questions:"Fragen", add_question:"Frage hinzufügen",
    question_n:(n)=>"Frage "+n, question_ph:"Schreibe hier die Frage…",
    answer_ph:"Antwort…", correct:"Richtig", add_answer:"+ Antwort hinzufügen",
    help_title:"Bedienungsanleitung", help_sub:"Vollständiges Handbuch, Schritt für Schritt.", help_nav:"Anleitung", help_book_user:"Benutzerhandbuch", help_book_admin:"Administratorhandbuch",
    qtype:"Typ", type_quiz:"Multiple Choice (4)", type_tf:"Wahr / Falsch", type_type:"Freie Antwort", type_num:"Numerisch (raten)",
    num_target:"Richtige Zahl", num_target_ph:"z. B. 42", num_tol:"Akzeptierte Toleranz",
    num_hint:"Antworten im Bereich ± Toleranz sind richtig; je näher, desto mehr Punkte. Toleranz 0 = nur exakt.",
    err_num:(n)=>`Frage ${n}: gib eine gültige Zahl ein.`, num_answer_ph:"Schreibe eine Zahl…",
    acc_primary_ph:"Die richtige Antwort (angezeigt)", acc_alt_ph:"Akzeptierte Variante (Synonym, Schreibweise)", acc_add:"Akzeptierte Variante hinzufügen",
    type_answer_ph:"Schreibe deine Antwort…", type_submit:"Senden", type_need_answer:"Schreibe eine Antwort.", you_wrote:"Du hast geschrieben:",
    gh_typing:"Teilnehmer tippen…", gh_gotit:"richtig",
    acc_hint:"Kleine Tippfehler werden ebenfalls akzeptiert. Die erste Variante wird als richtige Antwort angezeigt.",
    err_acc:(n)=>`Frage ${n}: füge mindestens eine akzeptierte Antwort hinzu.`,
    type_not_live:"Fragen mit freier Antwort funktionieren derzeit nur im Solo-/Reihum-Modus (nicht Live/Hausaufgabe).",
    tf_true:"Wahr", tf_false:"Falsch",
    time_limit:"Zeit", pts:"Punkte", pts_std:"Standard", pts_dbl:"Doppelt",
    sec:"s", save:"Quiz speichern", cancel:"Abbrechen",
    saved:"Quiz gespeichert!", deleted:"Quiz gelöscht",
    err_title:"Gib dem Quiz einen Titel.", err_noq:"Füge mindestens eine Frage hinzu.",
    err_qtext:(n)=>"Frage "+n+" hat keinen Text.",
    err_ans:(n)=>"Frage "+n+" braucht mindestens 2 Antworten.",
    err_corr:(n)=>"Markiere die richtige Antwort für Frage "+n+".",
    confirm_del:"Dieses Quiz endgültig löschen?",
    setup_title:"Spiel einrichten", choose_mode:"Spielmodus",
    mode_solo:"Solo", mode_solo_d:"Spiele allein und schlage deinen Rekord.",
    mode_hot:"Reihum", mode_hot_d:"Mehrere Spieler auf demselben Gerät.",
    players:"Spieler", player_ph:"Spielername",
    add_player:"+ Spieler hinzufügen", your_name:"Dein Name", you:"Du",
    start_game:"Spiel starten", need_player:"Füge mindestens einen Spieler hinzu.",
    get_ready:"Mach dich bereit!", q_of:(a,b)=>"Frage "+a+" von "+b,
    tap_answer:"Tippe die richtige Antwort", keys_hint:"Drücke 1-4 oder tippe eine Antwort",
    pass_to:"Gib das Gerät weiter an", tap_start:"Tippe, wenn du bereit bist",
    ready_q:"Bereit?", time_up:"Zeit abgelaufen!",
    verdict_ok:"Richtig!", verdict_no:"Falsch!", verdict_miss:"Keine Antwort",
    correct_was:"Richtige Antwort:", pts_earned:"Punkte", streak:(n)=>n+" in Folge 🔥",
    continue:"Weiter", scoreboard:"Rangliste",
    podium_title:"Glückwunsch!", winner_is:"Sieger",
    play_again:"Nochmal spielen", back_home:"Startseite", final_scores:"Endstand",
    stat_correct:"Richtige", stat_acc:"Genauigkeit", stat_pts:"Punkte", stat_streak:"Beste Serie",
    set_title:"Einstellungen", set_lang:"Sprache", set_lang_d:"Sprache der Oberfläche",
    set_sound:"Ton", set_sound_d:"Soundeffekte während des Spiels",
    set_about:"Undava ist ein handelsfreies Offline-Spiel in einer einzigen Datei. Deine Quiz werden nur auf diesem Gerät gespeichert. Nutze Exportieren, um sie als Dateien zu behalten.",
    close:"Schließen",
    import_title:"Quiz importieren", import_drop:"Lege eine .json-Datei hierher oder tippe zum Auswählen",
    import_paste:"…oder füge den JSON-Inhalt ein:", import_btn:"Importieren",
    import_ok:"Quiz importiert!", import_err:"Ungültige Datei. Prüfe das JSON-Format.",
    import_paste_ph:'{"title":"…","questions":[…]}',
    quit_q:"Spiel verlassen? Der Fortschritt geht verloren.", quit:"Verlassen",
    guestbook:"Feedback",
    fb_title:"Besucher-Feedback", fb_sub:"Hinterlasse einen Gedanken zu den Quiz und sieh, was andere geschrieben haben.",
    fb_recent:"Neueste Rückmeldungen", fb_empty:"Noch kein Feedback. Sei der Erste!",
    fb_name:"Name (optional)", fb_name_ph:"Wie sollen wir dich nennen?",
    fb_quiz:"Quiz (optional)", fb_pick_quiz:"— wähle ein Quiz —",
    fb_rating:"Bewertung", fb_msg:"Nachricht", fb_msg_ph:"Was hat dir gefallen? Was könnte besser sein?",
    fb_send:"Feedback senden", fb_anon:"Anonym",
    fb_need_rating:"Wähle eine Bewertung (1–5 Sterne).", fb_need_msg:"Schreibe eine Nachricht.",
    fb_thanks:"Danke für dein Feedback!", fb_thanks_mod:"Danke! Deine Nachricht erscheint nach der Freigabe.",
    fb_leave:"Feedback hinterlassen", fb_local_note:"(lokal gespeichert, auf diesem Gerät)",
    fb_approve:"Freigeben", fb_pending:"ausstehend",
    set_admin:"Administrator", set_admin_on:"Du bist als Administrator angemeldet.", set_admin_off:"Melde dich an, um Quiz zu bearbeiten.",
    login:"Anmelden", logout:"Abmelden", password:"Passwort", password_again:"Passwort wiederholen",
    admin_login:"Administrator-Anmeldung", admin_login_d:"Gib das Passwort ein, um Quiz zu erstellen und zu bearbeiten.",
    admin_setup:"Administrator-Passwort festlegen", admin_setup_d:"Das ist der erste Besuch. Wähle ein Passwort für den Admin-Bereich.",
    admin_create:"Passwort erstellen", admin_ok:"Angemeldet!", admin_out:"Abgemeldet.", admin_bad:"Falsches Passwort.",
    err_pw:"Gib das Passwort ein.", err_pw_short:"Das Passwort muss mindestens 6 Zeichen haben.", err_pw_match:"Die Passwörter stimmen nicht überein.",
    net_err:"Netzwerkfehler. Prüfe deine Verbindung.",
    live_nav:"Live",
    live_title:"Live-Sitzung", live_sub:"Wortwolke, Umfragen und Fragen — auf den Handys des Publikums, in Echtzeit.",
    live_join_code:"Mit Code beitreten", live_code_ph:"CODE", live_join:"Beitreten", live_host_new:"Oder starte eine neue Aktivität",
    live_t_cloud:"Wortwolke", live_t_cloud_d:"Das Publikum sendet Wörter, live angezeigt.",
    live_t_poll:"Live-Umfrage", live_t_poll_d:"Über Optionen abstimmen, Balken in Echtzeit.",
    live_t_qa:"Fragen & Ideen", live_t_qa_d:"Nachrichten des Publikums, mit Stimmen.",
    live_options:"Optionen", live_option:"Option", live_add_option:"Option hinzufügen",
    live_max_words:"Wie viele Wörter eine Person senden kann",
    live_prompt:"Frage / Thema", live_prompt_ph:"Z. B. Welches Wort beschreibt die Zukunft?", live_launch:"Sitzung starten",
    live_host_login:"Melde dich als Administrator an, um eine Sitzung zu leiten.",
    live_need_prompt:"Schreibe eine Frage oder ein Thema.", live_need_opts:"Füge mindestens 2 Optionen hinzu.", live_need_code:"Gib einen Code ein.",
    live_back:"Schließen", live_open:"OFFEN", live_closed_b:"GESCHLOSSEN",
    live_pause:"Pause", live_resume:"Fortsetzen", live_clear:"Löschen", live_clear_q:"Alle Antworten löschen?",
    live_end:"Beenden", live_end_q:"Die Sitzung endgültig beenden? Die Daten werden gelöscht.", live_ended:"Sitzung geschlossen.",
    live_responses:"Antworten", live_join_at:"Beitreten unter", live_copy:"Link kopieren", live_share:"Teilen", live_copied:"Link kopiert!",
    live_leave:"Verlassen", live_loading:"Lädt…", live_notfound:"Sitzung nicht gefunden oder beendet.",
    live_closed_note:"Der Gastgeber hat die Antworten pausiert.", live_live_results:"Live-Ergebnisse",
    live_word_ph:"Schreibe ein Wort…", live_qa_ph:"Schreibe deine Frage oder Idee…",
    live_send:"Senden", live_sent:"Gesendet!", live_slow:"Zu schnell — warte einen Moment.", live_closed:"Die Antworten sind geschlossen.",
    live_need_text:"Schreibe zuerst etwas.",
    live_words_left:(n)=>`Du kannst noch ${n} Wort/Wörter senden.`, live_thanks_words:"Danke! Sieh dir die Wortwolke unten an.",
    live_voted:"Stimme gezählt!", live_waiting:"Warte auf Antworten…", live_show:"Anzeigen", live_hide:"Ausblenden",
    live_png_name:"wortwolke", live_json_name:"live-ergebnisse",
    mode_live:"Live (Mehrspieler)", mode_live_d:"Leite am großen Bildschirm; das Publikum spielt am Handy.",
    game_host:"Live leiten", game_join_note:"Die Spieler treten über ihr Handy bei, indem sie den QR-Code scannen oder den angezeigten Code eingeben.",
    game_players:"Spieler", game_waiting_players:"Warte auf Spieler… teile den Code!",
    game_start:"Spiel starten", game_reveal:"Antwort zeigen", game_next:"Weiter", game_results:"Ergebnisse", game_replay:"Nochmal spielen",
    game_pick_name:"Wähle Name und Avatar", game_nickname:"Dein Spitzname", game_enter:"Spiel betreten", game_need_name:"Gib einen Spitznamen ein.",
    game_youre_in:"Du bist dabei!", game_look_screen:"Schau auf den Hauptbildschirm.", game_lobby_closed:"Das Spiel hat bereits begonnen.",
    game_locked:"Antwort gesendet!", game_wait_others:"Warte auf die anderen Spieler…", game_times_up:"Zeit abgelaufen!",
    game_correct:"Richtig!", game_wrong:"Falsch", game_missed:"Keine Antwort", game_your_rank:"Dein Platz:", game_finished:"Spiel vorbei!",
    shuffle_opt:"Fragen und Antworten mischen", nick_gen:"Zufälliger Spitzname",
    team_opt:"Team-Modus", team_word:"Teams", team_pick:"Wähle dein Team", team_standings:"Team-Rangliste",
    spin_nav:"Rad", spin_title:"Glücksrad", spin_sub:"Gib Namen oder Optionen ein und drehe, um zufällig zu wählen.",
    spin_go:"Drehen", spin_items:"Einträge", spin_ph:"Ein Eintrag pro Zeile…\nAnna\nBen\nClara", spin_need:"Füge mindestens 2 Einträge hinzu.",
    spin_elim:"Gewinner nach jeder Drehung entfernen", spin_shuffle:"Mischen", spin_reset:"Zurücksetzen", spin_default:"Anna|Ben|Clara|David|Eva|Frank",
    live_filter:"Schimpfwortfilter", live_filter_off:"Aus", live_filter_on:"An", live_filter_hint:"Maskiert automatisch vulgäre Wörter in Antworten (ersetzt sie durch ***).",
    live_qa_mod:"Fragen-Moderation", live_qa_mod_off:"Aus (alle sichtbar)", live_qa_mod_on:"Freigabe verlangen",
    live_qa_mod_hint:"Mit Freigabe erscheinen die Fragen dem Publikum erst, nachdem du sie freigegeben hast.",
    qa_approve:"Freigeben", qa_pending:"Ausstehend", qa_pending_count:(n)=>`${n} ausstehend`, live_sent_mod:"Zur Freigabe gesendet.",
    deck_export:"Exportieren", deck_import:"Importieren", deck_import_empty:"Keine gültigen Aktivitäten in der Datei.", deck_import_bad:"Ungültige Datei.", deck_imported:(n)=>`${n} Aktivitäten importiert.`,
    live_mode_single:"Einzelne Aktivität", live_mode_deck:"Präsentation",
    deck_title:"Titel der Präsentation", deck_title_ph:"z. B. Atlantykron-Workshop — Rückmeldungen",
    deck_slides:"Aktivitäten", deck_empty:"Noch keine Aktivitäten. Wähle oben einen Typ und füge eine hinzu.",
    deck_add_new:"Aktivität hinzufügen", deck_add_slide:"Zur Präsentation hinzufügen", deck_added:"Aktivität hinzugefügt.",
    deck_launch:"Präsentation starten", deck_need_slides:"Füge mindestens eine Aktivität hinzu.", deck_full:"Maximal 50 Aktivitäten.",
    deck_default_title:"Präsentation", deck_slide:"Aktivität", deck_prev:"Zurück", deck_next:"Weiter",
    live_t_scale:"Skalen (Likert)", live_t_scale_d:"Mehrere Aussagen von 1 bis 5 bewertet (Ablehnung→Zustimmung).",
    live_t_points:"100 Punkte", live_t_points_d:"Die Teilnehmer verteilen ein Punktebudget auf die Optionen.",
    live_statements:"Aussagen", live_statement:"Aussage", live_add_stmt:"Aussage hinzufügen",
    live_scale_hint:"Jede Aussage wird auf einer Skala von 1 (starke Ablehnung) bis 5 (starke Zustimmung) bewertet.",
    live_points_opts:"Optionen", live_points_hint:"Jeder Teilnehmer verteilt 100 Punkte auf diese Optionen.",
    live_need_stmts:"Füge mindestens 2 Aussagen hinzu.",
    scale_lo:"starke Ablehnung", scale_hi:"starke Zustimmung", scale_all:"Bewerte jede Aussage.",
    pt_remaining:"Verbleibend:", pt_useall:"Nutze alle deine Punkte", pt_budget_each:(n)=>`je ${n} Punkte`,
    live_t_rating:"Bewertung / NPS", live_t_rating_d:"Sternebewertung oder NPS-Wert 0–10.",
    live_t_rank:"Rangliste", live_t_rank_d:"Die Teilnehmer ordnen die Optionen nach Vorliebe.",
    live_rating_scale:"Skalentyp", live_rank_opts:"Zu ordnende Optionen", rank_submit:"Reihenfolge senden",
    rt_avg:"Durchschnitt", rt_prom:"Fürsprecher", rt_pass:"Passive", rt_det:"Kritiker", rk_foot:"nach Durchschnittsrang sortiert (niedriger = besser)",
    nps_low:"Überhaupt nicht wahrscheinlich", nps_high:"Äußerst wahrscheinlich",
    qa_answered:"Beantwortet", qa_star:"Hervorheben", qa_mark:"Als beantwortet markieren",
    offline_banner:"Du bist offline — Solo, Bibliothek und Editor funktionieren. Live-Modi kehren nach der Wiederverbindung zurück.",
    install_hint:"Undava als App installieren", install_btn:"Installieren", offline_feature:"Offline nicht verfügbar.", online_back:"Du bist wieder online.",
    mode_assign:"Im eigenen Tempo", mode_assign_d:"Weise ein Quiz zu; jeder löst es allein, im eigenen Tempo.",
    assign_host:"Hausaufgabe zuweisen", assign_note:"Die Teilnehmer treten über ihr Handy bei und lösen im eigenen Tempo. Die Ergebnisse sammeln sich hier.",
    assign_intro:(n)=>`${n} Fragen · im eigenen Tempo`, assign_start:"Starten",
    assign_done:"fertig", assign_joined:"beigetreten", assign_none_done:"Noch niemand ist fertig.",
    assign_closed:"Diese Aufgabe ist geschlossen.", assign_closed_note:"Der Gastgeber hat die Aufgabe geschlossen.",
    assign_complete:"Du bist fertig!", assign_finish:"Ergebnis ansehen",
    game_report:"Bericht", report_title:"Spielbericht", report_byq:"Nach Frage", report_byp:"Nach Spieler",
    report_players:"Spieler", report_questions:"Fragen", report_avg_score:"Durchschnittspunktzahl", report_accuracy:"Genauigkeit", report_avg_time:"Durchschnittszeit",
    report_correct:"richtig", report_answered:"beantwortet", report_csv:"CSV", report_json:"JSON", report_print:"Drucken",
    report_easy:"leicht", report_med:"mittel", report_hard:"schwer", report_back:"Zurück", report_no_data:"Noch keine Daten.", report_player:"Spieler",
  }
};
function t(k,...a){ const v=I18N[state.lang][k]; return typeof v==="function"?v(...a):v; }

/* ---------------- sample quizzes ---------------- */
function samples(){ return [
  {id:"s_general", sample:true, color:"#2f6bff", title:{ro:"Cultură generală",en:"General knowledge"},
   desc:{ro:"Un amestec de istorie, geografie și literatură.",en:"A mix of history, geography and literature."},
   questions:[
    {text:{ro:"Care este capitala Australiei?",en:"What is the capital of Australia?"},time:20,points:1000,type:"quiz",
     answers:[{text:{ro:"Canberra",en:"Canberra"},correct:true},{text:{ro:"Sydney",en:"Sydney"},correct:false},{text:{ro:"Melbourne",en:"Melbourne"},correct:false},{text:{ro:"Perth",en:"Perth"},correct:false}]},
    {text:{ro:"În ce an a căzut Zidul Berlinului?",en:"In what year did the Berlin Wall fall?"},time:20,points:1000,type:"quiz",
     answers:[{text:{ro:"1989",en:"1989"},correct:true},{text:{ro:"1991",en:"1991"},correct:false},{text:{ro:"1985",en:"1985"},correct:false},{text:{ro:"1979",en:"1979"},correct:false}]},
    {text:{ro:"Cine a scris „Romeo și Julieta”?",en:"Who wrote “Romeo and Juliet”?"},time:20,points:1000,type:"quiz",
     answers:[{text:{ro:"W. Shakespeare",en:"W. Shakespeare"},correct:true},{text:{ro:"Charles Dickens",en:"Charles Dickens"},correct:false},{text:{ro:"Lev Tolstoi",en:"Leo Tolstoy"},correct:false},{text:{ro:"J. W. Goethe",en:"J. W. Goethe"},correct:false}]},
    {text:{ro:"Care este cel mai înalt munte de pe Pământ?",en:"What is the tallest mountain on Earth?"},time:15,points:1000,type:"quiz",
     answers:[{text:{ro:"Everest",en:"Everest"},correct:true},{text:{ro:"K2",en:"K2"},correct:false},{text:{ro:"Mont Blanc",en:"Mont Blanc"},correct:false},{text:{ro:"Kilimanjaro",en:"Kilimanjaro"},correct:false}]},
    {text:{ro:"Câte continente sunt pe Pământ?",en:"How many continents are there on Earth?"},time:15,points:1000,type:"quiz",
     answers:[{text:{ro:"7",en:"7"},correct:true},{text:{ro:"5",en:"5"},correct:false},{text:{ro:"6",en:"6"},correct:false},{text:{ro:"8",en:"8"},correct:false}]},
  ]},
  {id:"s_space", sample:true, color:"#7b2ff7", title:{ro:"Spațiu & Astronomie",en:"Space & Astronomy"},
   desc:{ro:"Planete, stele și mecanică orbitală.",en:"Planets, stars and orbital mechanics."},
   questions:[
    {text:{ro:"Care planetă e cunoscută drept „Planeta Roșie”?",en:"Which planet is known as the “Red Planet”?"},time:15,points:1000,type:"quiz",
     answers:[{text:{ro:"Marte",en:"Mars"},correct:true},{text:{ro:"Venus",en:"Venus"},correct:false},{text:{ro:"Jupiter",en:"Jupiter"},correct:false},{text:{ro:"Saturn",en:"Saturn"},correct:false}]},
    {text:{ro:"Cât durează lumina Soarelui să ajungă la Pământ?",en:"How long does sunlight take to reach Earth?"},time:20,points:1000,type:"quiz",
     answers:[{text:{ro:"~8 minute",en:"~8 minutes"},correct:true},{text:{ro:"~1 secundă",en:"~1 second"},correct:false},{text:{ro:"~1 oră",en:"~1 hour"},correct:false},{text:{ro:"~8 secunde",en:"~8 seconds"},correct:false}]},
    {text:{ro:"Care e cea mai apropiată stea de noi (după Soare)?",en:"What is the closest star to us (after the Sun)?"},time:20,points:1000,type:"quiz",
     answers:[{text:{ro:"Proxima Centauri",en:"Proxima Centauri"},correct:true},{text:{ro:"Sirius",en:"Sirius"},correct:false},{text:{ro:"Betelgeuse",en:"Betelgeuse"},correct:false},{text:{ro:"Vega",en:"Vega"},correct:false}]},
    {text:{ro:"Câte luni (sateliți) are planeta Marte?",en:"How many moons does Mars have?"},time:15,points:1000,type:"quiz",
     answers:[{text:{ro:"2",en:"2"},correct:true},{text:{ro:"0",en:"0"},correct:false},{text:{ro:"1",en:"1"},correct:false},{text:{ro:"4",en:"4"},correct:false}]},
    {text:{ro:"Pe Venus, o zi durează mai mult decât un an.",en:"On Venus, a day lasts longer than a year."},time:15,points:1000,type:"tf",
     answers:[{text:{ro:"Adevărat",en:"True"},correct:true},{text:{ro:"Fals",en:"False"},correct:false}]},
    {text:{ro:"Ce instrument a detectat primele unde gravitaționale?",en:"Which instrument detected the first gravitational waves?"},time:25,points:2000,type:"quiz",
     answers:[{text:{ro:"LIGO",en:"LIGO"},correct:true},{text:{ro:"Hubble",en:"Hubble"},correct:false},{text:{ro:"LHC",en:"LHC"},correct:false},{text:{ro:"ALMA",en:"ALMA"},correct:false}]},
  ]},
  {id:"s_tech", sample:true, color:"#18bd6b", title:{ro:"Tehnologie & Web",en:"Technology & Web"},
   desc:{ro:"Cum funcționează internetul și codul.",en:"How the internet and code work."},
   questions:[
    {text:{ro:"Ce înseamnă „HTML”?",en:"What does “HTML” stand for?"},time:20,points:1000,type:"quiz",
     answers:[{text:{ro:"HyperText Markup Language",en:"HyperText Markup Language"},correct:true},{text:{ro:"High Tech Modern Language",en:"High Tech Modern Language"},correct:false},{text:{ro:"Hyperlink Text Logic",en:"Hyperlink Text Logic"},correct:false},{text:{ro:"Home Tool Markup",en:"Home Tool Markup"},correct:false}]},
    {text:{ro:"Cine a inventat World Wide Web?",en:"Who invented the World Wide Web?"},time:20,points:1000,type:"quiz",
     answers:[{text:{ro:"Tim Berners-Lee",en:"Tim Berners-Lee"},correct:true},{text:{ro:"Bill Gates",en:"Bill Gates"},correct:false},{text:{ro:"Steve Jobs",en:"Steve Jobs"},correct:false},{text:{ro:"Linus Torvalds",en:"Linus Torvalds"},correct:false}]},
    {text:{ro:"Ce limbaj rulează nativ în browser?",en:"Which language runs natively in the browser?"},time:15,points:1000,type:"quiz",
     answers:[{text:{ro:"JavaScript",en:"JavaScript"},correct:true},{text:{ro:"Python",en:"Python"},correct:false},{text:{ro:"C++",en:"C++"},correct:false},{text:{ro:"Java",en:"Java"},correct:false}]},
    {text:{ro:"HTTPS criptează traficul dintre browser și server.",en:"HTTPS encrypts traffic between browser and server."},time:15,points:1000,type:"tf",
     answers:[{text:{ro:"Adevărat",en:"True"},correct:true},{text:{ro:"Fals",en:"False"},correct:false}]},
    {text:{ro:"Cine a inițiat kernelul Linux?",en:"Who started the Linux kernel?"},time:20,points:1000,type:"quiz",
     answers:[{text:{ro:"Linus Torvalds",en:"Linus Torvalds"},correct:true},{text:{ro:"Richard Stallman",en:"Richard Stallman"},correct:false},{text:{ro:"Ken Thompson",en:"Ken Thompson"},correct:false},{text:{ro:"Dennis Ritchie",en:"Dennis Ritchie"},correct:false}]},
  ]},
];}

/* ---------------- state + storage ---------------- */
const STORE_KEY="quizfarafrontiere_v1";
const QFF=(typeof window!=="undefined"&&window.QFF)?window.QFF:{server:false,csrf:"",admin:false,adminExists:true,moderate:false};
const state={ lang:"ro", sound:true, screen:"home", quizzes:[], editing:null, play:null, setup:null, modal:null, importBuf:"",
  server:!!QFF.server, admin:!!QFF.admin, csrf:QFF.csrf||"", moderate:!!QFF.moderate,
  feedback:[], fbCtx:null, fbForm:null, fbBusy:false, loading:false, gbScope:"all" };

/* preferences (language + sound) always live in localStorage */
function loadPrefs(){ try{ return JSON.parse(localStorage.getItem(STORE_KEY))||{}; }catch(e){ return {}; } }
function savePrefs(){ try{ const c=loadPrefs(); localStorage.setItem(STORE_KEY, JSON.stringify(Object.assign(c,{ lang:state.lang, sound:state.sound }))); }catch(e){} }
function saveStore(){ savePrefs(); } /* kept for existing call-sites: prefs only */

/* local content store (quizzes + feedback) — used only when NOT on a server */
function loadLocalData(){ try{ return JSON.parse(localStorage.getItem(STORE_KEY+"_data"))||{}; }catch(e){ return {}; } }
function writeLocalData(d){ try{ localStorage.setItem(STORE_KEY+"_data", JSON.stringify(d)); }catch(e){} }
function localQuizzes(){ const d=loadLocalData(); return Array.isArray(d.userQuizzes)?d.userQuizzes:[]; }
function setLocalQuizzes(a){ const d=loadLocalData(); d.userQuizzes=a; writeLocalData(d); }
function localFeedback(){ const d=loadLocalData(); return Array.isArray(d.feedback)?d.feedback:[]; }
function setLocalFeedback(a){ const d=loadLocalData(); d.feedback=a; writeLocalData(d); }

/* JSON API helper (server mode) */
async function api(action, opts){
  opts=opts||{};
  const parts=String(action).split("&"); let url="?api="+encodeURIComponent(parts[0]); const rest=parts.slice(1).join("&"); if(rest) url+="&"+rest;
  if(opts.query){ for(const k in opts.query){ if(opts.query[k]!==undefined) url+="&"+encodeURIComponent(k)+"="+encodeURIComponent(opts.query[k]); } }
  const init={ method: opts.method || (opts.body!==undefined?"POST":"GET"), headers:{} };
  if(opts.body!==undefined){ init.headers["Content-Type"]="application/json"; init.body=JSON.stringify(Object.assign({csrf:state.csrf}, opts.body)); }
  const r=await fetch(url, init);
  let data=null; try{ data=await r.json(); }catch(e){}
  if(!r.ok){ const err=new Error((data&&data.error)?data.error:("HTTP "+r.status)); err.status=r.status; err.data=data; throw err; }
  return data||{};
}

/* Store: server when available, otherwise localStorage */
const Store={
  async loadQuizzes(){ if(state.server){ const d=await api("quizzes"); return Array.isArray(d.quizzes)?d.quizzes:[]; } return localQuizzes(); },
  async saveQuiz(q){ if(state.server){ const d=await api("save",{body:{quiz:q}}); return d.quiz; }
    const a=localQuizzes(); const i=a.findIndex(x=>x.id===q.id); if(i>=0)a[i]=q; else a.push(q); setLocalQuizzes(a); return q; },
  async deleteQuiz(id){ if(state.server){ await api("delete",{body:{id:id}}); return; } setLocalQuizzes(localQuizzes().filter(x=>x.id!==id)); },
  async loadFeedback(scope){ if(state.server){ const d=await api("feedback",{query:(scope&&scope!=="all")?{quiz:scope}:undefined}); return Array.isArray(d.feedback)?d.feedback:[]; }
    let f=localFeedback().slice(); if(scope&&scope!=="all") f=f.filter(x=>x.quizId===scope); f.sort((a,b)=>b.ts-a.ts); return f; },
  async addFeedback(entry){ if(state.server){ const d=await api("feedback",{body:entry}); return d.entry; }
    const e=Object.assign({ts:Date.now(),status:"pub"}, entry); const a=localFeedback(); a.push(e); setLocalFeedback(a); return e; },
  async deleteFeedback(ts){ if(state.server){ await api("fbdelete",{body:{ts:Number(ts)}}); return; } setLocalFeedback(localFeedback().filter(x=>String(x.ts)!==String(ts))); },
  async approveFeedback(ts){ if(state.server){ await api("fbapprove",{body:{ts:Number(ts)}}); return; } const a=localFeedback(); const it=a.find(x=>String(x.ts)===String(ts)); if(it)it.status="pub"; setLocalFeedback(a); },
  async adminLogin(pw){ const d=await api("login",{body:{password:pw}}); state.admin=true; if(d.csrf)state.csrf=d.csrf; return d; },
  async adminLogout(){ await api("logout",{body:{}}); state.admin=false; },
  async adminSetup(pw){ const d=await api("setup_admin",{body:{password:pw}}); state.admin=true; if(d.csrf)state.csrf=d.csrf; return d; }
};

function apiErr(e){ if(e&&e.message){ if(/Failed to fetch|NetworkError|load failed/i.test(e.message)) return t("net_err"); return e.message; } return t("net_err"); }
function fmtDate(ts){ try{ return new Date(Number(ts)).toLocaleString(({ro:"ro-RO",en:"en-GB",fr:"fr-FR",it:"it-IT",es:"es-ES",pt:"pt-PT",de:"de-DE"}[state.lang]||"en-GB"),{dateStyle:"medium",timeStyle:"short"}); }catch(e){ return ""; } }
function requireAdmin(){ if(state.server && !state.admin){ state.modal="login"; renderModal(); return false; } return true; }
async function refreshQuizzes(){ let user=[]; try{ user=await Store.loadQuizzes(); }catch(e){} state.quizzes=[...samples(), ...user]; }

async function initStore(){
  const p=loadPrefs();
  if(p.lang) state.lang=p.lang;
  if(typeof p.sound==="boolean") state.sound=p.sound;
  await refreshQuizzes();
}

/* ---------------- utilities ---------------- */
function uid(){ return "q_"+Date.now().toString(36)+Math.random().toString(36).slice(2,7); }
function esc(s){ return String(s==null?"":s).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c])); }
function L(obj){ // localize a {ro,en} field, or pass through a plain string
  if(obj==null) return "";
  if(typeof obj==="string") return obj;
  return obj[state.lang] || obj.ro || obj.en || Object.values(obj)[0] || "";
}
const AV_COLORS=["#ffd23f","#ff4e8a","#2ee6c4","#2f6bff","#18bd6b","#e8385a","#f7a823","#a06bff","#ff7847","#36c5f0"];
function avColor(i){ return AV_COLORS[i%AV_COLORS.length]; }
const NICK_RO={adj:["Vesel","Rapid","Isteț","Curajos","Zglobiu","Șiret","Trăsnit","Voios","Vioi","Sprinten","Jucăuș","Ager"], noun:["Vulpe","Bufniță","Delfin","Tigru","Arici","Panda","Vultur","Lup","Castor","Zmeu","Rândunică","Veveriță"]};
const NICK_EN={adj:["Happy","Swift","Clever","Brave","Jolly","Sly","Zany","Merry","Witty","Nimble","Playful","Lively"], noun:["Fox","Owl","Dolphin","Tiger","Hedgehog","Panda","Eagle","Wolf","Beaver","Otter","Sparrow","Squirrel"]};
function randomNick(){ const P=(state.lang==="ro")?NICK_RO:NICK_EN; const a=P.adj[Math.floor(Math.random()*P.adj.length)]; const n=P.noun[Math.floor(Math.random()*P.noun.length)]; return (a+n+(10+Math.floor(Math.random()*90))).slice(0,20); }
function initials(name){ const p=name.trim().split(/\s+/); return ((p[0]||"?")[0]+(p[1]?p[1][0]:"")).toUpperCase(); }

const SHAPES=[
  '<svg viewBox="0 0 100 100"><polygon points="50,14 90,86 10,86"/></svg>',      // triangle
  '<svg viewBox="0 0 100 100"><polygon points="50,8 92,50 50,92 8,50"/></svg>',  // diamond
  '<svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="42"/></svg>',           // circle
  '<svg viewBox="0 0 100 100"><rect x="12" y="12" width="76" height="76" rx="10"/></svg>' // square
];
const ANSCLASS=["a1","a2","a3","a4"];

function toast(msg){
  const old=document.querySelector(".toast"); if(old) old.remove();
  const el=document.createElement("div"); el.className="toast"; el.textContent=msg;
  document.body.appendChild(el); setTimeout(()=>el.remove(),2200);
}

/* ---------------- sound engine (Web Audio) ---------------- */
const Sound={
  ctx:null,
  on(){ return state.sound; },
  ensure(){ if(!this.ctx){ try{ this.ctx=new (window.AudioContext||window.webkitAudioContext)(); }catch(e){ this.ctx=null; } } if(this.ctx&&this.ctx.state==="suspended"){ this.ctx.resume(); } return this.ctx; },
  note(freq,start,dur,type="sine",vol=0.18){
    const c=this.ctx; if(!c) return;
    const o=c.createOscillator(), g=c.createGain();
    o.type=type; o.frequency.value=freq;
    g.gain.setValueAtTime(0,start);
    g.gain.linearRampToValueAtTime(vol,start+0.012);
    g.gain.exponentialRampToValueAtTime(0.0001,start+dur);
    o.connect(g).connect(c.destination); o.start(start); o.stop(start+dur+0.02);
  },
  tick(){ if(!this.on()||!this.ensure()) return; this.note(1100,this.ctx.currentTime,0.05,"square",0.05); },
  tickUrgent(){ if(!this.on()||!this.ensure()) return; this.note(760,this.ctx.currentTime,0.07,"square",0.09); },
  correct(){ if(!this.on()||!this.ensure()) return; const n=this.ctx.currentTime; [[660,0],[880,0.09],[1320,0.18]].forEach(([f,d])=>this.note(f,n+d,0.18,"triangle",0.16)); },
  wrong(){ if(!this.on()||!this.ensure()) return; const n=this.ctx.currentTime; this.note(240,n,0.18,"sawtooth",0.12); this.note(160,n+0.1,0.26,"sawtooth",0.12); },
  whoosh(){ if(!this.on()||!this.ensure()) return; const n=this.ctx.currentTime; this.note(420,n,0.16,"sine",0.10); this.note(620,n+0.05,0.14,"sine",0.08); },
  countTick(){ if(!this.on()||!this.ensure()) return; this.note(520,this.ctx.currentTime,0.1,"sine",0.12); },
  go(){ if(!this.on()||!this.ensure()) return; this.note(880,this.ctx.currentTime,0.2,"sine",0.16); },
  fanfare(){ if(!this.on()||!this.ensure()) return; const n=this.ctx.currentTime; [[523,0],[659,0.12],[784,0.24],[1047,0.40],[784,0.6],[1047,0.72]].forEach(([f,d])=>this.note(f,n+d,0.32,"triangle",0.17)); }
};

/* =========================================================================
   RENDER
   ========================================================================= */
function render(){
  if(state.screen!=="livehost" && state.screen!=="livejoin") stopPoll();
  renderTopBar();
  const r=document.getElementById("root");
  const inPlay = state.screen==="play";
  document.getElementById("topbar").style.display = inPlay ? "none" : "";
  switch(state.screen){
    case "home": r.innerHTML=viewHome(); break;
    case "library": r.innerHTML=viewLibrary(); break;
    case "editor": r.innerHTML=viewEditor(); break;
    case "setup": r.innerHTML=viewSetup(); break;
    case "import": r.innerHTML=viewImport(); break;
    case "guestbook": r.innerHTML=viewGuestbook(); break;
    case "livehub": r.innerHTML=viewLiveHub(); break;
    case "livehost": r.innerHTML=viewLiveHost(); break;
    case "livejoin": r.innerHTML=viewLiveJoin(); break;
    case "report": r.innerHTML=viewReport(); break;
    case "play": r.innerHTML=viewPlay(); break;
    case "spinner": r.innerHTML=viewSpinner(); break;
    case "help": r.innerHTML=viewHelp(); break;
    default: r.innerHTML=viewHome();
  }
  // bind per-screen
  if(state.screen==="editor") bindEditor();
  if(state.screen==="setup") bindSetup();
  if(state.screen==="import") bindImport();
  if(state.screen==="play") bindPlay();
  if(state.screen==="livehub") afterLiveHub();
  if(state.screen==="livehost") afterLiveHost();
  if(state.screen==="livejoin") afterLiveJoin();
  if(state.screen==="spinner") afterSpinner();
  if(state.screen==="help") window.scrollTo(0,0);
  updatePwaBar();
  renderModal();
}

function renderTopBar(){
  const tb=document.getElementById("topbar");
  tb.innerHTML=`
    <div class="brand" data-action="home">
      <div class="logo"></div>
      <div><h1>Undava</h1><small>${esc(t("tagline"))}</small></div>
    </div>
    <div class="tools">
      <div class="langtog">
        <button class="${state.lang==="ro"?"on":""}" data-action="lang" data-v="ro">RO</button>
        <button class="${state.lang==="en"?"on":""}" data-action="lang" data-v="en">EN</button>
        <button class="${state.lang==="fr"?"on":""}" data-action="lang" data-v="fr">FR</button>
        <button class="${state.lang==="it"?"on":""}" data-action="lang" data-v="it">IT</button>
        <button class="${state.lang==="es"?"on":""}" data-action="lang" data-v="es">ES</button>
        <button class="${state.lang==="pt"?"on":""}" data-action="lang" data-v="pt">PT</button>
        <button class="${state.lang==="de"?"on":""}" data-action="lang" data-v="de">DE</button>
      </div>
      <button class="iconbtn" data-action="sound" title="${esc(t("set_sound"))}">${state.sound?"🔊":"🔇"}</button>
      <button class="iconbtn" data-action="settings" title="${esc(t("settings"))}">⚙️</button>
    </div>`;
}

const SPIN_COLORS=["#ffd23f","#ff5a5f","#34d3ff","#18bd6b","#b06bff","#ff8c42","#ff6ad5","#9fe84f","#62d0ff","#ff4e8a","#2ee6c4","#c9a24b"];
Object.assign(state, { spinner:{ items:[], result:null, spinning:false, elim:false }, spinAngle:0 });
function spinnerWheel(items){ const n=items.length, cx=150, cy=150, r=146, seg=360/n; let paths="", labels="";
  for(let i=0;i<n;i++){ const a0=(i*seg-90)*Math.PI/180, a1=((i+1)*seg-90)*Math.PI/180;
    const x0=cx+r*Math.cos(a0), y0=cy+r*Math.sin(a0), x1=cx+r*Math.cos(a1), y1=cy+r*Math.sin(a1); const large=seg>180?1:0;
    paths+='<path d="M'+cx+','+cy+' L'+x0.toFixed(1)+','+y0.toFixed(1)+' A'+r+','+r+' 0 '+large+' 1 '+x1.toFixed(1)+','+y1.toFixed(1)+' Z" fill="'+SPIN_COLORS[i%SPIN_COLORS.length]+'" stroke="rgba(0,0,0,.18)" stroke-width="1"/>';
    const mid=(i*seg+seg/2-90)*Math.PI/180, lr=r*0.62, lx=cx+lr*Math.cos(mid), ly=cy+lr*Math.sin(mid), rot=(i*seg+seg/2);
    const raw=items[i], txt=raw.length>14?raw.slice(0,13)+"…":raw;
    labels+='<text x="'+lx.toFixed(1)+'" y="'+ly.toFixed(1)+'" fill="#1a0b2e" font-size="'+(n>10?11:(n>6?13:15))+'" font-weight="800" text-anchor="middle" dominant-baseline="central" transform="rotate('+rot.toFixed(1)+' '+lx.toFixed(1)+' '+ly.toFixed(1)+')">'+esc(txt)+'</text>';
  }
  return '<svg viewBox="0 0 300 300" class="spin-svg">'+paths+labels+'<circle cx="150" cy="150" r="24" fill="#16092e" stroke="#fff" stroke-width="3"/></svg>';
}
function viewSpinner(){ const sp=state.spinner; const items=sp.items||[];
  const wheel = items.length>=2 ? spinnerWheel(items) : '<div class="spin-need">'+esc(t("spin_need"))+'</div>';
  return '<div class="wrap">'
    +'<a class="backlink" data-action="home">← '+esc(t("back_home"))+'</a>'
    +'<div class="page-head"><div><h2>🎡 '+esc(t("spin_title"))+'</h2><div class="sub">'+esc(t("spin_sub"))+'</div></div></div>'
    +'<div class="spin-layout">'
      +'<div class="spin-stage">'
        +'<div class="spin-wrap"><div class="spin-pointer"></div><div id="spin-wheel" class="spin-wheel" style="transform:rotate('+(state.spinAngle||0)+'deg)">'+wheel+'</div></div>'
        +'<button class="btn btn-primary btn-lg spin-go" data-action="spinspin"'+((items.length<2||sp.spinning)?' disabled':'')+'>'+(sp.spinning?'…':'🎯 '+esc(t("spin_go")))+'</button>'
        +(sp.result?'<div class="spin-result">🎉 <b>'+esc(sp.result)+'</b></div>':'<div class="spin-result-ph"></div>')
      +'</div>'
      +'<div class="spin-side">'
        +'<label class="spin-lbl">'+esc(t("spin_items"))+' ('+items.length+')</label>'
        +'<textarea id="spin-items" class="textarea spin-ta" rows="10" placeholder="'+esc(t("spin_ph"))+'">'+esc(items.join("\n"))+'</textarea>'
        +'<label class="chk spin-chk"><input type="checkbox" data-action="spinelim"'+(sp.elim?' checked':'')+'> '+esc(t("spin_elim"))+'</label>'
        +'<div class="spin-btns"><button class="btn btn-ghost sm" data-action="spinshuffle">🔀 '+esc(t("spin_shuffle"))+'</button><button class="btn btn-ghost sm" data-action="spinreset">↺ '+esc(t("spin_reset"))+'</button></div>'
      +'</div>'
    +'</div>'
  +'</div>';
}
function afterSpinner(){ const ta=document.getElementById("spin-items"); if(ta && !ta._b){ ta._b=true; ta.addEventListener("input",()=>{ spinnerSyncItems(); const w=document.getElementById("spin-wheel"); const items=state.spinner.items; if(w){ w.style.transition="none"; w.innerHTML = items.length>=2 ? spinnerWheel(items) : '<div class="spin-need">'+esc(t("spin_need"))+'</div>'; } const go=document.querySelector(".spin-go"); if(go) go.disabled=(items.length<2||state.spinner.spinning); const lbl=document.querySelector(".spin-lbl"); if(lbl) lbl.textContent=t("spin_items")+" ("+items.length+")"; }); } }
function spinnerSyncItems(){ const ta=document.getElementById("spin-items"); if(!ta) return; const items=ta.value.split("\n").map(x=>x.trim()).filter(Boolean).slice(0,24); state.spinner.items=items; try{ localStorage.setItem(STORE_KEY+"_spin", JSON.stringify(items)); }catch(e){} }
function spinLoad(){ let saved=[]; try{ saved=JSON.parse(localStorage.getItem(STORE_KEY+"_spin")||"[]"); }catch(e){} state.spinner.items=(saved&&saved.length)?saved:t("spin_default").split("|"); }
function spinWheel(){ spinnerSyncItems(); const sp=state.spinner; const items=sp.items; if(items.length<2||sp.spinning) return;
  const n=items.length, seg=360/n, i=Math.floor(Math.random()*n), center=i*seg+seg/2, cur=state.spinAngle||0;
  const target=cur + 360*5 + (((360-center)%360) - (cur%360) + 720)%360;
  sp.spinning=true; sp.result=null;
  const w=document.getElementById("spin-wheel"), go=document.querySelector(".spin-go");
  if(go){ go.disabled=true; go.textContent="…"; }
  const rez=document.querySelector(".spin-result"); if(rez) rez.className="spin-result-ph";
  if(w){ w.style.transition="transform 4.2s cubic-bezier(.15,.72,.16,1)"; requestAnimationFrame(()=>{ w.style.transform="rotate("+target+"deg)"; }); }
  setTimeout(()=>{ state.spinAngle=target; sp.result=items[i]; sp.spinning=false; if(sp.elim && items.length>2){ const ni=items.slice(); ni.splice(i,1); state.spinner.items=ni; try{ localStorage.setItem(STORE_KEY+"_spin", JSON.stringify(ni)); }catch(e){} } render(); }, 4350);
}
/* ---------------- HOME ---------------- */
function mdSlug(s){ return String(s).toLowerCase().replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,""); }
function mdRender(md){
  function inl(s){ s=esc(s); var codes=[];
    s=s.replace(/`([^`]+)`/g,function(m,c){ codes.push(c); return "\x00"+(codes.length-1)+"\x00"; });
    s=s.replace(/\*\*([^*]+)\*\*/g,"<strong>$1</strong>");
    s=s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g,'<a href="$2" target="_blank" rel="noopener">$1</a>');
    s=s.replace(/\x00(\d+)\x00/g,function(m,i){ return "<code>"+codes[+i]+"</code>"; });
    return s; }
  var lines=String(md).replace(/\r\n/g,"\n").split("\n"), out=[], i=0;
  while(i<lines.length){
    var ln=lines[i];
    if(/^```/.test(ln)){ var buf=[]; i++; while(i<lines.length&&!/^```/.test(lines[i])){ buf.push(esc(lines[i])); i++; } i++; out.push('<pre class="md-pre"><code>'+buf.join("\n")+'</code></pre>'); continue; }
    var h=ln.match(/^(#{1,4})\s+(.*)$/);
    if(h){ var lv=h[1].length; var idA=(lv<=2)?(' id="mdh-'+mdSlug(h[2])+'"'):''; out.push('<h'+lv+' class="md-h'+lv+'"'+idA+'>'+inl(h[2])+'</h'+lv+'>'); i++; continue; }
    if(/^---+\s*$/.test(ln)){ out.push('<hr class="md-hr">'); i++; continue; }
    if(/^>\s?/.test(ln)){ var qb=[]; while(i<lines.length&&/^>\s?/.test(lines[i])){ qb.push(lines[i].replace(/^>\s?/,"")); i++; } out.push('<blockquote class="md-bq">'+inl(qb.join(" "))+'</blockquote>'); continue; }
    if(/^\|/.test(ln)&&i+1<lines.length&&/^\|[\s:|-]+\|?\s*$/.test(lines[i+1])){
      var head=ln.split("|").slice(1,-1).map(function(x){return x.trim();}); i+=2; var rows=[];
      while(i<lines.length&&/^\|/.test(lines[i])){ rows.push(lines[i].split("|").slice(1,-1).map(function(x){return x.trim();})); i++; }
      var tb='<table class="md-tbl"><thead><tr>'+head.map(function(c){return "<th>"+inl(c)+"</th>";}).join("")+"</tr></thead><tbody>";
      tb+=rows.map(function(r){ return "<tr>"+r.map(function(c){return "<td>"+inl(c)+"</td>";}).join("")+"</tr>"; }).join("");
      out.push(tb+"</tbody></table>"); continue; }
    if(/^\s*[-*]\s+/.test(ln)){ var items=[];
      while(i<lines.length&&/^\s*[-*]\s+/.test(lines[i])){ var it=lines[i].replace(/^\s*[-*]\s+/,""); var cb="";
        if(/^\[ \]\s*/.test(it)){ cb='<span class="md-cb">\u2610</span> '; it=it.replace(/^\[ \]\s*/,""); }
        else if(/^\[[xX]\]\s*/.test(it)){ cb='<span class="md-cb on">\u2611</span> '; it=it.replace(/^\[[xX]\]\s*/,""); }
        items.push("<li>"+cb+inl(it)+"</li>"); i++; }
      out.push('<ul class="md-ul">'+items.join("")+"</ul>"); continue; }
    if(/^\s*\d+\.\s+/.test(ln)){ var oi=[];
      while(i<lines.length&&/^\s*\d+\.\s+/.test(lines[i])){ oi.push("<li>"+inl(lines[i].replace(/^\s*\d+\.\s+/,""))+"</li>"); i++; }
      out.push('<ol class="md-ol">'+oi.join("")+"</ol>"); continue; }
    if(/^\s*$/.test(ln)){ i++; continue; }
    var pb=[ln]; i++; while(i<lines.length&&!/^\s*$/.test(lines[i])&&!/^(#{1,4}\s|```|>|\||\s*[-*]\s|\s*\d+\.\s)/.test(lines[i])&&!/^---+\s*$/.test(lines[i])){ pb.push(lines[i]); i++; }
    out.push("<p>"+inl(pb.join(" "))+"</p>");
  }
  return out.join("\n");
}
function viewHelp(){
  var hasAdmin=!!document.getElementById("manual-admin");
  var book=state.helpBook||"user"; if(book==="admin"&&!hasAdmin) book="user";
  var lang=state.lang||"ro"; var el=document.getElementById("manual-"+book+"-"+lang)||document.getElementById("manual-"+book); var md=el?el.textContent:"";
  var toc=[]; md.replace(/\r\n/g,"\n").split("\n").forEach(function(l){ var m=l.match(/^##\s+(.*)$/); if(m) toc.push(m[1]); });
  var tocHtml=toc.map(function(x){ return '<a class="help-toc-item" data-action="mdjump" data-t="mdh-'+mdSlug(x)+'">'+esc(x)+'</a>'; }).join("");
  var switcher=hasAdmin?('<div class="help-books"><button class="help-book'+(book==="user"?" on":"")+'" data-action="helpbook" data-b="user">\ud83d\udcd8 '+esc(t("help_book_user"))+'</button><button class="help-book'+(book==="admin"?" on":"")+'" data-action="helpbook" data-b="admin">\ud83d\udd27 '+esc(t("help_book_admin"))+'</button></div>'):'';
  return '<div class="wrap help-wrap">'
    +'<a class="backlink" data-action="gohome">\u2190 '+esc(t("back_home"))+'</a>'
    +'<div class="page-head"><div><h2>\u2753 '+esc(t("help_title"))+'</h2><div class="sub">'+esc(t("help_sub"))+'</div></div></div>'
    +switcher
    +'<div class="help-layout"><nav class="help-toc">'+tocHtml+'</nav><article class="md-body">'+mdRender(md)+'</article></div>'
  +'</div>';
}
function viewHome(){
  return `<div class="wrap center-stage">
    <section class="hero">
      <span class="kicker">${esc(t("home_kicker"))}</span>
      <h2>${esc(t("home_title_1"))}<br><span class="pop">${esc(t("home_title_2"))}</span></h2>
      <p>${esc(t("home_sub"))}</p>
      <div class="cta-row">
        <button class="btn btn-primary btn-lg" data-action="library">▶ ${esc(t("play"))}</button>
        <button class="btn btn-pink btn-lg" data-action="create">✎ ${esc(t("create"))}</button>
        <button class="btn btn-ghost btn-lg" data-action="import">⇪ ${esc(t("import"))}</button>
        <button class="btn btn-ghost btn-lg" data-action="guestbook">💬 ${esc(t("guestbook"))}</button>
        <button class="btn btn-ghost btn-lg" data-action="live">📡 ${esc(t("live_nav"))}</button>
        <button class="btn btn-ghost btn-lg" data-action="spinner">🎡 ${esc(t("spin_nav"))}</button>
        <button class="btn btn-ghost btn-lg" data-action="help">❓ ${esc(t("help_nav"))}</button>
      </div>
      <div class="feat">
        <span><b>●</b> ${esc(t("feat_offline"))}</span>
        <span><b>●</b> ${esc(t("feat_free"))}</span>
        <span><b>●</b> ${esc(t("feat_priv"))}</span>
        <span><b>●</b> ${esc(t("feat_open"))}</span>
      </div>
    </section>
  </div>`;
}

/* ---------------- LIBRARY ---------------- */
function quizCard(q){
  const n=q.questions.length;
  const isS=!!q.sample;
  const canEdit=(!state.server)||state.admin;
  return `<div class="qcard">
    <div class="top" style="background:linear-gradient(135deg,${q.color||"#2f6bff"},rgba(0,0,0,.25))">
      <span class="badge">${isS?(state.lang==="ro"?"DEMO":"DEMO"):(state.lang==="ro"?"AL TĂU":"YOURS")}</span>
      <span class="qn">${n}</span>
    </div>
    <div class="body">
      <h3>${esc(L(q.title))}</h3>
      <div class="desc">${esc(L(q.desc))||"&nbsp;"}</div>
      <div class="meta"><span>📋 ${esc(t("q_count",n))}</span></div>
    </div>
    <div class="actions">
      <button class="btn btn-primary" data-action="setup" data-id="${q.id}">▶ ${esc(t("playbtn"))}</button>
      ${canEdit?`<button class="btn btn-ghost mini" data-action="edit" data-id="${q.id}" title="${esc(t("edit"))}">✎</button>`:""}
      ${canEdit?`<button class="btn btn-ghost mini" data-action="dup" data-id="${q.id}" title="${esc(t("duplicate"))}">⧉</button>`:""}
      <button class="btn btn-ghost mini" data-action="export" data-id="${q.id}" title="${esc(t("export"))}">⬇</button>
      ${(canEdit&&!isS)?`<button class="btn btn-ghost mini" data-action="delquiz" data-id="${q.id}" title="${esc(t("del"))}">🗑</button>`:""}
    </div>
  </div>`;
}
function viewLibrary(){
  const sample=state.quizzes.filter(q=>q.sample);
  const mine=state.quizzes.filter(q=>!q.sample);
  return `<div class="wrap">
    <a class="backlink" data-action="home">← ${esc(t("back_home"))}</a>
    <div class="page-head">
      <div><h2>${esc(t("lib_title"))}</h2><div class="sub">${esc(t("lib_sub"))}</div></div>
      <button class="btn btn-pink" data-action="create">✎ ${esc(t("create"))}</button>
    </div>
    <div class="page-head" style="margin-bottom:12px"><h2 style="font-size:20px">${esc(t("lib_mine"))}</h2></div>
    <div class="grid">${ mine.length? mine.map(quizCard).join("") : `<div class="card-empty">${esc(t("lib_empty"))}</div>` }</div>
    <div class="page-head" style="margin:34px 0 12px"><h2 style="font-size:20px">${esc(t("lib_samples"))}</h2></div>
    <div class="grid">${ sample.map(quizCard).join("") }</div>
  </div>`;
}

/* ---------------- EDITOR ---------------- */
function normText(s){ return String(s==null?"":s).toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g,"").replace(/[^a-z0-9\s]/g," ").replace(/\s+/g," ").trim(); }
function levDist(a,b){ const m=a.length,n=b.length; if(!m)return n; if(!n)return m; const d=[]; for(let j=0;j<=n;j++)d[j]=j; for(let i=1;i<=m;i++){ let prev=d[0]; d[0]=i; for(let j=1;j<=n;j++){ const tmp=d[j]; d[j]=Math.min(d[j]+1,d[j-1]+1,prev+(a.charCodeAt(i-1)===b.charCodeAt(j-1)?0:1)); prev=tmp; } } return d[n]; }
function matchText(input, accepted){ const g=normText(input); if(!g) return false; const list=Array.isArray(accepted)?accepted:[accepted];
  for(const acc of list){ const a=normText(acc); if(!a) continue; if(g===a) return true; const allowed=Math.min(2,Math.floor(a.length/4)); if(allowed>0 && levDist(g,a)<=allowed) return true; } return false; }
function acceptedAnswers(q){ return (q.answers||[]).map(a=>L(a.text)).filter(x=>String(x||"").trim()); }
function numParse(s){ const v=parseFloat(String(s==null?"":s).replace(",",".").trim()); return isFinite(v)?v:null; }
function numScore(guess,target,tol){ const g=numParse(guess),t=numParse(target); if(g===null||t===null) return {ok:false,close:0}; const d=Math.abs(g-t); const to=Math.abs(tol||0); const ok=d<=to; const close=to>0?Math.max(0,1-0.5*(d/to)):1; return {ok, close: ok?close:0}; }
function blankQuestion(){ return { text:"", time:20, points:1000, type:"quiz",
  answers:[{text:"",correct:true},{text:"",correct:false},{text:"",correct:false},{text:"",correct:false}] }; }
function blankQuiz(){ return { id:uid(), color:AV_COLORS[Math.floor(Math.random()*4)+1], title:"", desc:"", questions:[blankQuestion()] }; }

// normalize an existing quiz (possibly localized) into an editable flat structure (strings in current lang for samples)
function toEditable(q){
  const clone=JSON.parse(JSON.stringify(q));
  clone.title=L(clone.title); clone.desc=L(clone.desc);
  clone.questions=clone.questions.map(qq=>({
    text:L(qq.text), time:qq.time||20, points:qq.points||1000, type:qq.type||"quiz",
    answers:qq.answers.map(a=>({text:L(a.text), correct:!!a.correct})), tol:qq.tol||0
  }));
  return clone;
}

function viewEditor(){
  const q=state.editing; const isNew=q.__new;
  const times=[5,10,20,30,60,120];
  return `<div class="wrap">
    <a class="backlink" data-action="library">← ${esc(t("library"))}</a>
    <div class="page-head"><div><h2>${esc(isNew?t("new_quiz"):t("edit_quiz"))}</h2></div></div>
    <div class="panel">
      <div class="field"><label>${esc(t("quiz_title"))}</label>
        <input class="input" id="ed-title" maxlength="80" placeholder="${esc(t("quiz_title_ph"))}" value="${esc(q.title)}"></div>
      <div class="field" style="margin-bottom:0"><label>${esc(t("quiz_desc"))}</label>
        <input class="input" id="ed-desc" maxlength="140" placeholder="${esc(t("quiz_desc_ph"))}" value="${esc(q.desc)}"></div>
    </div>
    <div class="page-head" style="margin:8px 0 14px"><h2 style="font-size:20px">${esc(t("questions"))} <span style="color:var(--muted2)">(${q.questions.length})</span></h2></div>
    <div id="qlist">${ q.questions.map((qq,i)=>questionEditor(qq,i,times)).join("") }</div>
    <div class="editor-actions">
      <button class="btn btn-ghost" data-action="addq">＋ ${esc(t("add_question"))}</button>
      <div style="flex:1"></div>
      <button class="btn btn-ghost" data-action="library">${esc(t("cancel"))}</button>
      <button class="btn btn-primary" data-action="savequiz">✓ ${esc(t("save"))}</button>
    </div>
  </div>`;
}
function questionEditor(qq,i,times){
  const tf=qq.type==="tf", isType=qq.type==="type", isNum=qq.type==="num";
  let body;
  if(isType){
    const accRows=qq.answers.map((a,j)=>`<div class="acc-row"><span class="acc-ic">${j===0?"✓":"≈"}</span><input class="input acc-text" data-q="${i}" data-a="${j}" placeholder="${esc(j===0?t("acc_primary_ph"):t("acc_alt_ph"))}" value="${esc(a.text)}" maxlength="90">${(qq.answers.length>1)?`<button class="rm" data-action="accrm" data-q="${i}" data-a="${j}" title="✕">✕</button>`:`<span style="width:36px"></span>`}</div>`).join("");
    body=`<div class="acc-rows">${accRows}</div>${qq.answers.length<6?`<button class="btn btn-ghost" style="margin-top:10px;font-size:14px;padding:9px 16px" data-action="accadd" data-q="${i}">${esc(t("acc_add"))}</button>`:""}<div class="acc-hint">💡 ${esc(t("acc_hint"))}</div>`;
  } else if(isNum){
    body=`<div class="num-rows"><div class="num-row"><label class="num-lbl">${esc(t("num_target"))}</label><input class="input num-target" data-q="${i}" inputmode="decimal" placeholder="${esc(t("num_target_ph"))}" value="${esc(qq.answers[0]?qq.answers[0].text:"")}" maxlength="18"></div><div class="num-row"><label class="num-lbl">${esc(t("num_tol"))}</label><span class="num-pm">±</span><input class="input num-tol" data-q="${i}" inputmode="decimal" placeholder="0" value="${esc(qq.tol!=null?String(qq.tol):"0")}" maxlength="12"></div></div><div class="acc-hint">💡 ${esc(t("num_hint"))}</div>`;
  } else {
    const ansRows=qq.answers.map((a,j)=>{ const txt=tf?(j===0?t("tf_true"):t("tf_false")):a.text;
      return `<div class="ans-row"><div class="shape sc${j+1}">${SHAPES[j]}</div><input class="input ans-text" data-q="${i}" data-a="${j}" placeholder="${esc(t("answer_ph"))}" value="${esc(txt)}" ${tf?"readonly":""} maxlength="90"><div class="corr"><input type="radio" name="corr-${i}" data-q="${i}" data-a="${j}" class="corr-radio" ${a.correct?"checked":""}><label>${esc(t("correct"))}</label></div>${(!tf && qq.answers.length>2)?`<button class="rm" data-action="rmans" data-q="${i}" data-a="${j}" title="✕">✕</button>`:`<span style="width:36px"></span>`}</div>`; }).join("");
    body=`<div class="ans-rows">${ansRows}</div>${(!tf && qq.answers.length<4)?`<button class="btn btn-ghost" style="margin-top:10px;font-size:14px;padding:9px 16px" data-action="addans" data-q="${i}">${esc(t("add_answer"))}</button>`:""}`;
  }
  return `<div class="qedit" data-qi="${i}">
    <div class="qhead">
      <span class="qnum">${esc(t("question_n",i+1))}</span>
      <div class="spacer"></div>
      <select class="select" style="width:auto;min-width:150px;padding:9px 36px 9px 12px;font-size:14px" data-action="qtype" data-q="${i}">
        <option value="quiz" ${(!tf&&!isType&&!isNum)?"selected":""}>${esc(t("type_quiz"))}</option>
        <option value="tf" ${tf?"selected":""}>${esc(t("type_tf"))}</option>
        <option value="type" ${isType?"selected":""}>${esc(t("type_type"))}</option>
        <option value="num" ${isNum?"selected":""}>${esc(t("type_num"))}</option>
      </select>
      <select class="select" style="width:auto;padding:9px 34px 9px 12px;font-size:14px" data-action="qtime" data-q="${i}">
        ${times.map(s=>`<option value="${s}" ${qq.time===s?"selected":""}>${s}${esc(t("sec"))}</option>`).join("")}
      </select>
      <select class="select" style="width:auto;padding:9px 34px 9px 12px;font-size:14px" data-action="qpoints" data-q="${i}">
        <option value="1000" ${qq.points===1000?"selected":""}>${esc(t("pts_std"))}</option>
        <option value="2000" ${qq.points===2000?"selected":""}>${esc(t("pts_dbl"))}</option>
      </select>
      <button class="iconbtn" style="width:38px;height:38px" data-action="qup" data-q="${i}" title="↑">↑</button>
      <button class="iconbtn" style="width:38px;height:38px" data-action="qdown" data-q="${i}" title="↓">↓</button>
      <button class="iconbtn" style="width:38px;height:38px;background:rgba(232,56,90,.16);color:#ff90a8" data-action="rmq" data-q="${i}" title="🗑">🗑</button>
    </div>
    <div class="qbody">
      <div class="field" style="margin-bottom:12px">
        <textarea class="textarea q-text" data-q="${i}" placeholder="${esc(t("question_ph"))}" maxlength="200">${esc(qq.text)}</textarea>
      </div>
      ${body}
    </div>
  </div>`;
}

function bindEditor(){
  const q=state.editing;
  const root=document.getElementById("root");
  // text fields update model without re-render (preserve focus)
  const title=document.getElementById("ed-title"); if(title) title.oninput=e=>{ q.title=e.target.value; };
  const desc=document.getElementById("ed-desc"); if(desc) desc.oninput=e=>{ q.desc=e.target.value; };
  root.querySelectorAll(".q-text").forEach(el=>el.oninput=e=>{ q.questions[+e.target.dataset.q].text=e.target.value; });
  root.querySelectorAll(".ans-text").forEach(el=>el.oninput=e=>{ q.questions[+e.target.dataset.q].answers[+e.target.dataset.a].text=e.target.value; });
  root.querySelectorAll(".acc-text").forEach(el=>el.oninput=e=>{ q.questions[+e.target.dataset.q].answers[+e.target.dataset.a].text=e.target.value; });
  root.querySelectorAll(".num-target").forEach(el=>el.oninput=e=>{ const qq=q.questions[+e.target.dataset.q]; if(!qq.answers[0]) qq.answers[0]={text:"",correct:true}; qq.answers[0].text=e.target.value; });
  root.querySelectorAll(".num-tol").forEach(el=>el.oninput=e=>{ q.questions[+e.target.dataset.q].tol=e.target.value; });
  root.querySelectorAll(".corr-radio").forEach(el=>el.onchange=e=>{
    const qi=+e.target.dataset.q, ai=+e.target.dataset.a;
    q.questions[qi].answers.forEach((a,k)=>a.correct=(k===ai));
  });
  // selects
  root.querySelectorAll('[data-action="qtime"]').forEach(el=>el.onchange=e=>{ q.questions[+e.target.dataset.q].time=+e.target.value; });
  root.querySelectorAll('[data-action="qpoints"]').forEach(el=>el.onchange=e=>{ q.questions[+e.target.dataset.q].points=+e.target.value; });
  root.querySelectorAll('[data-action="qtype"]').forEach(el=>el.onchange=e=>{
    const qi=+e.target.dataset.q; const qq=q.questions[qi];
    if(e.target.value==="tf"){ qq.type="tf"; qq.answers=[{text:"",correct:true},{text:"",correct:false}]; }
    else if(e.target.value==="type"){ qq.type="type"; qq.answers=[{text:"",correct:true}]; }
    else if(e.target.value==="num"){ qq.type="num"; qq.answers=[{text:"",correct:true}]; if(qq.tol==null) qq.tol=0; }
    else { qq.type="quiz"; qq.answers=[{text:"",correct:true},{text:"",correct:false},{text:"",correct:false},{text:"",correct:false}]; }
    rerenderQList();
  });
}
function rerenderQList(){
  const times=[5,10,20,30,60,120];
  const q=state.editing;
  const list=document.getElementById("qlist");
  list.innerHTML=q.questions.map((qq,i)=>questionEditor(qq,i,times)).join("");
  // update count header
  const h=document.querySelector('.page-head h2 span'); if(h) h.textContent="("+q.questions.length+")";
  bindEditor();
}

/* ---------------- SETUP ---------------- */
function viewSetup(){
  const su=state.setup; const quiz=su.quiz;
  return `<div class="wrap">
    <a class="backlink" data-action="library">← ${esc(t("library"))}</a>
    <div class="page-head"><div><h2>${esc(L(quiz.title))}</h2><div class="sub">${esc(t("q_count",quiz.questions.length))}</div></div></div>
    <div class="panel">
      <label style="display:block;font-size:13px;font-weight:800;color:var(--muted);margin-bottom:12px;letter-spacing:.3px">${esc(t("choose_mode"))}</label>
      <div class="mode-grid">
        <div class="mode-card ${su.mode==="solo"?"on":""}" data-action="mode" data-v="solo">
          <div class="ico">🎯</div><h3>${esc(t("mode_solo"))}</h3><p>${esc(t("mode_solo_d"))}</p></div>
        <div class="mode-card ${su.mode==="hotseat"?"on":""}" data-action="mode" data-v="hotseat">
          <div class="ico">👥</div><h3>${esc(t("mode_hot"))}</h3><p>${esc(t("mode_hot_d"))}</p></div>
        <div class="mode-card ${su.mode==="live"?"on":""}" data-action="mode" data-v="live">
          <div class="ico">📡</div><h3>${esc(t("mode_live"))}</h3><p>${esc(t("mode_live_d"))}</p></div>
        <div class="mode-card ${su.mode==="assign"?"on":""}" data-action="mode" data-v="assign">
          <div class="ico">📝</div><h3>${esc(t("mode_assign"))}</h3><p>${esc(t("mode_assign_d"))}</p></div>
      </div>
      <label class="chk setup-shuffle"><input type="checkbox" data-action="shuftoggle" ${su.shuffle?"checked":""}> 🔀 ${esc(t("shuffle_opt"))}</label>
      ${su.mode==="live" ? `<div class="setup-teams"><label class="chk"><input type="checkbox" data-action="teamtoggle" ${su.teamCount>0?"checked":""}> 👥 ${esc(t("team_opt"))}</label>${su.teamCount>0?`<div class="team-count">${[2,3,4].map(n=>`<button class="tc-btn${su.teamCount===n?" on":""}" data-action="teamcount" data-n="${n}">${n} ${esc(t("team_word"))}</button>`).join("")}</div>`:""}</div>` : ""}
      ${ (su.mode==="live"||su.mode==="assign")
        ? `<div class="live-mode-note">${su.mode==="assign"?"📝 "+esc(t("assign_note")):"📡 "+esc(t("game_join_note"))}</div>`
        : `<label style="display:block;font-size:13px;font-weight:800;color:var(--muted);margin-bottom:12px;letter-spacing:.3px">${esc(t("players"))}</label>
      <div class="players-list" id="players">${su.players.map((p,i)=>playerRow(p,i,su.mode)).join("")}</div>
      ${ su.mode==="hotseat" && su.players.length<8 ? `<button class="btn btn-ghost" data-action="addplayer" style="font-size:14px">${esc(t("add_player"))}</button>` : "" }` }
    </div>
    <button class="btn btn-primary btn-lg btn-block" data-action="startgame">🚀 ${esc(su.mode==="live"?t("game_host"):su.mode==="assign"?t("assign_host"):t("start_game"))}</button>
  </div>`;
}
function playerRow(name,i,mode){
  const canRemove = mode==="hotseat" && i>0;
  return `<div class="player-row">
    <div class="av" style="background:${avColor(i)}">${esc(initials(name||"?"))}</div>
    <input class="input player-name" data-i="${i}" placeholder="${esc(mode==="solo"?t("your_name"):t("player_ph"))}" value="${esc(name)}" maxlength="20">
    ${canRemove?`<button class="iconbtn" style="background:rgba(232,56,90,.16);color:#ff90a8" data-action="rmplayer" data-i="${i}">✕</button>`:""}
  </div>`;
}
function bindSetup(){
  const su=state.setup;
  document.querySelectorAll(".player-name").forEach(el=>{
    el.oninput=e=>{ su.players[+e.target.dataset.i]=e.target.value; const av=e.target.previousElementSibling; if(av) av.textContent=initials(e.target.value||"?"); };
  });
}

/* ---------------- IMPORT ---------------- */
function viewImport(){
  return `<div class="wrap">
    <a class="backlink" data-action="library">← ${esc(t("library"))}</a>
    <div class="page-head"><div><h2>${esc(t("import_title"))}</h2></div></div>
    <div class="panel">
      <div class="dropzone" id="dropzone"><div class="ico">📂</div><div>${esc(t("import_drop"))}</div></div>
      <input type="file" id="filein" accept=".json,application/json" class="hidden">
      <div class="field" style="margin:18px 0 0"><label>${esc(t("import_paste"))}</label>
        <textarea class="textarea" id="pastein" style="min-height:120px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px" placeholder='${esc(t("import_paste_ph"))}'></textarea></div>
      <button class="btn btn-primary" style="margin-top:14px" data-action="doimport">⇪ ${esc(t("import_btn"))}</button>
    </div>
  </div>`;
}
function bindImport(){
  const dz=document.getElementById("dropzone");
  const fi=document.getElementById("filein");
  if(dz){
    dz.onclick=()=>fi.click();
    dz.ondragover=e=>{ e.preventDefault(); dz.classList.add("over"); };
    dz.ondragleave=()=>dz.classList.remove("over");
    dz.ondrop=e=>{ e.preventDefault(); dz.classList.remove("over"); const f=e.dataTransfer.files[0]; if(f) readFile(f); };
  }
  if(fi) fi.onchange=e=>{ const f=e.target.files[0]; if(f) readFile(f); };
}
function readFile(f){
  const rd=new FileReader();
  rd.onload=()=>{ tryImport(rd.result); };
  rd.readAsText(f);
}
function tryImport(txt){
  if(!requireAdmin()) return;
  let arr;
  try{ const data=JSON.parse(txt); arr=Array.isArray(data)?data:[data]; }
  catch(e){ toast(t("import_err")); return; }
  const quizzes=arr.map(sanitizeImported).filter(Boolean);
  if(!quizzes.length){ toast(t("import_err")); return; }
  (async()=>{
    try{
      for(const q of quizzes){ await Store.saveQuiz(q); }
      await refreshQuizzes();
      toast(t("import_ok"));
      state.screen="library"; render();
    }catch(e){ toast(apiErr(e)); }
  })();
}
function sanitizeImported(raw){
  if(!raw||typeof raw!=="object"||!Array.isArray(raw.questions)||!raw.questions.length) return null;
  const q={ id:uid(), color:typeof raw.color==="string"?raw.color:AV_COLORS[1], title:raw.title||"Quiz", desc:raw.desc||"", questions:[] };
  raw.questions.forEach(qq=>{
    if(q.questions.length>=100) return;
    if(!qq||!Array.isArray(qq.answers)) return;
    const type=(qq.type==="tf")?"tf":((qq.type==="type")?"type":((qq.type==="num")?"num":"quiz"));
    let ans, tol=0;
    if(type==="type"){ ans=qq.answers.slice(0,6).map(a=>({text:(a&&a.text!=null)?String(a.text):"",correct:true})).filter(a=>String(a.text).trim()); if(ans.length<1) return; }
    else if(type==="num"){ const tgt=(qq.answers[0]&&qq.answers[0].text!=null)?String(qq.answers[0].text):""; if(!isFinite(parseFloat(tgt.replace(",",".")))) return; ans=[{text:tgt.trim(),correct:true}]; tol=Math.abs(parseFloat(String(qq.tol||"0").replace(",","."))||0); }
    else { if(qq.answers.length<2) return; ans=qq.answers.slice(0,4).map(a=>({text:(a&&a.text!=null)?a.text:"", correct:!!(a&&a.correct)})); if(!ans.some(a=>a.correct)) ans[0].correct=true; }
    q.questions.push({ text:qq.text||"", time:[5,10,20,30,60,120].includes(qq.time)?qq.time:20,
      points:qq.points===2000?2000:1000, type:type, answers:ans, tol:tol });
  });
  return q.questions.length?q:null;
}

/* ---------------- EXPORT ---------------- */
function exportQuiz(q){
  const out=JSON.parse(JSON.stringify(q)); delete out.sample; delete out.__new;
  const blob=new Blob([JSON.stringify(out,null,2)],{type:"application/json"});
  const url=URL.createObjectURL(blob);
  const a=document.createElement("a");
  a.href=url; a.download=(L(q.title)||"quiz").replace(/[^\w\-]+/g,"_").toLowerCase()+".json";
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(()=>URL.revokeObjectURL(url),1500);
}

/* =========================================================================
   GAME ENGINE
   ========================================================================= */
function startGame(){
  const su=state.setup;
  if(su.mode==="live"){ createGame(); return; }
  if(su.mode==="assign"){ createAssign(); return; }
  let players=su.players.map(s=>String(s).trim()).filter(Boolean);
  if(su.mode==="solo"){ players=[players[0]||t("you")]; }
  if(!players.length){ toast(t("need_player")); return; }
  Sound.ensure();
  state.play={
    quiz:maybeShuffleQuiz(su.quiz), mode:su.mode,
    participants:players.map((nm,i)=>({name:nm, idx:i, score:0, streak:0, best:0, correct:0, history:[]})),
    qIndex:0, turnIndex:0, phase:"countdown", count:3,
    roundAnswers:[], roundResults:null, _timer:null
  };
  state.screen="play";
  render();
  runCountdown();
}

function curQuestion(){ return state.play.quiz.questions[state.play.qIndex]; }

function runCountdown(){
  const p=state.play; p.phase="countdown"; p.count=3; render();
  Sound.countTick();
  const iv=setInterval(()=>{
    p.count--;
    if(p.count>0){ Sound.countTick(); render(); }
    else { clearInterval(iv); Sound.go(); beginRound(); }
  },800);
  p._timer=()=>clearInterval(iv);
}

function beginRound(){
  const p=state.play;
  p.roundAnswers=[]; p.roundResults=null; p.turnIndex=0;
  if(p.mode==="hotseat"){ p.phase="pass"; render(); }
  else { startTurn(); }
}

function startTurn(){
  const p=state.play; p.phase="question"; p.answered=false;
  render();
  startTimer();
}

function startTimer(){
  const p=state.play; const q=curQuestion(); const limit=(q.time||20)*1000;
  const ring=document.getElementById("ring"); const ringSpan=document.getElementById("ring-num");
  const bar=document.getElementById("tbar"); const barFill=document.getElementById("tbar-fill");
  const t0=performance.now(); let lastSec=Math.ceil(limit/1000);
  stopTimer();
  function frame(){
    const el=performance.now()-t0; const rem=Math.max(0,limit-el); const frac=rem/limit;
    const secs=Math.ceil(rem/1000);
    if(ringSpan) ringSpan.textContent=secs;
    if(ring) ring.style.setProperty("--deg",(frac*360)+"deg");
    if(barFill) barFill.style.width=(frac*100)+"%";
    const warn=secs<=5;
    if(ring) ring.classList.toggle("warn",warn);
    if(bar) bar.classList.toggle("warn",warn);
    if(secs!==lastSec){ lastSec=secs; if(secs<=5&&secs>0){ Sound.tickUrgent(); } }
    if(rem<=0){ stopTimer(); submitAnswer(null); return; }
    p._raf=requestAnimationFrame(frame);
  }
  p._tStart=t0; p._tLimit=limit;
  p._raf=requestAnimationFrame(frame);
}
function stopTimer(){ const p=state.play; if(p&&p._raf){ cancelAnimationFrame(p._raf); p._raf=null; } }

function submitAnswer(answerIndex){
  const p=state.play; if(p.answered) return; p.answered=true; stopTimer();
  const q=curQuestion();
  const elapsed=p._tStart?Math.min(performance.now()-p._tStart,p._tLimit):p._tLimit;
  const correct = answerIndex!=null && !!q.answers[answerIndex] && !!q.answers[answerIndex].correct;
  p.roundAnswers[p.turnIndex]={answerIndex, elapsed, correct};
  // immediate feedback sound only in solo (hotseat hides correctness until reveal)
  if(p.mode==="solo"){ correct?Sound.correct():Sound.wrong(); doReveal(); }
  else { Sound.whoosh(); p.turnIndex++;
    if(p.turnIndex < p.participants.length){ p.phase="pass"; render(); }
    else { doReveal(); }
  }
}

function submitTextAnswer(text){
  const p=state.play; if(p.answered) return; const q=curQuestion(); if(q.type!=="type"&&q.type!=="num") return;
  p.answered=true; stopTimer();
  const elapsed=p._tStart?Math.min(performance.now()-p._tStart,p._tLimit):p._tLimit;
  let correct, close=1;
  if(q.type==="num"){ const r=numScore(text, q.answers[0]&&q.answers[0].text, q.tol||0); correct=r.ok; close=r.close; }
  else { correct=matchText(text, acceptedAnswers(q)); }
  p.roundAnswers[p.turnIndex]={answerIndex:null, text:text, elapsed, correct, close};
  if(p.mode==="solo"){ correct?Sound.correct():Sound.wrong(); doReveal(); }
  else { Sound.whoosh(); p.turnIndex++;
    if(p.turnIndex < p.participants.length){ p.phase="pass"; render(); }
    else { doReveal(); }
  }
}
function doReveal(){
  const p=state.play; const q=curQuestion();
  // compute pre-update ranks
  const preSorted=[...p.participants].sort((a,b)=>b.score-a.score);
  p.participants.forEach(pt=>pt._preRank=preSorted.indexOf(pt)+1);
  // compute results + apply scores once
  p.roundResults=p.participants.map((pt,i)=>{
    const a=p.roundAnswers[i]||{answerIndex:null,elapsed:p._tLimit,correct:false};
    let pts=0, newStreak=pt.streak;
    if(a.correct){
      const ratio=Math.min(a.elapsed/((q.time||20)*1000),1);
      const base=Math.round((1-ratio/2)*(q.points||1000)*((q.type==="num"&&a.close!=null)?a.close:1));
      newStreak=pt.streak+1;
      const bonus=Math.min(newStreak-1,5)*100;
      pts=base+bonus;
      pt.correct++; if(newStreak>pt.best) pt.best=newStreak;
    } else { newStreak=0; }
    pt.streak=newStreak; pt.score+=pts;
    pt.history.push({correct:a.correct, pts});
    return {name:pt.name, idx:pt.idx, answerIndex:a.answerIndex, text:a.text, correct:a.correct, pts, streak:newStreak, answered:(a.answerIndex!=null)||(a.text!=null&&a.text!=="")};
  });
  // post ranks
  const postSorted=[...p.participants].sort((a,b)=>b.score-a.score);
  p.participants.forEach(pt=>pt._postRank=postSorted.indexOf(pt)+1);
  p.phase="reveal"; render();
}

function afterReveal(){
  const p=state.play;
  if(p.mode==="hotseat" && p.participants.length>1){ p.phase="scoreboard"; render(); }
  else { nextRound(); }
}
function nextRound(){
  const p=state.play; p.qIndex++;
  if(p.qIndex < p.quiz.questions.length){ runCountdown(); }
  else { showPodium(); }
}
function showPodium(){
  const p=state.play; p.phase="podium"; render();
  Sound.fanfare();
  launchConfetti();
}

/* ---------------- PLAY VIEW ---------------- */
function viewPlay(){
  const p=state.play; if(!p) return "";
  const q=curQuestion();
  const total=p.quiz.questions.length;
  const me = p.mode==="hotseat" ? p.participants[p.turnIndex] : p.participants[0];

  const strip = `<div class="stage-strip">
    <div class="pill">📋 ${p.qIndex+1}/${total}</div>
    ${ p.mode==="hotseat" && (p.phase==="question") ? `<div class="pill" style="background:${avColor(me.idx)};color:#16092e;border:none">👤 ${esc(me.name)}</div>` : "" }
    <button class="pill" data-action="quitgame" style="cursor:pointer">✕</button>
    ${ p.mode==="solo" ? `<div class="pill score">⭐ ${p.participants[0].score}</div>` : `<div class="pill score">${esc(t("scoreboard"))}</div>` }
  </div>`;

  let main="";
  if(p.phase==="countdown"){
    main=`<div class="countdown">
      <div class="qof">${esc(t("q_of",p.qIndex+1,total))}</div>
      <div class="big" key="${p.count}">${p.count>0?p.count:""}</div>
      <div class="qtext">${esc(L(q.text))}</div>
    </div>`;
  } else if(p.phase==="pass"){
    const who=p.participants[p.turnIndex];
    main=`<div class="pass">
      <div class="av-big" style="background:${avColor(who.idx)}">${esc(initials(who.name))}</div>
      <div><div style="font-size:14px;color:var(--muted);font-weight:700;letter-spacing:1px;text-transform:uppercase">${esc(t("pass_to"))}</div>
      <h2>${esc(who.name)}</h2></div>
      <p>${esc(t("tap_start"))}</p>
      <button class="btn btn-primary btn-lg" data-action="beginturn">${esc(t("ready_q"))} ▶</button>
    </div>`;
  } else if(p.phase==="question"){
    if(q.type==="type"||q.type==="num"){
      main=`<div class="q-area">
        <div class="q-card">${esc(L(q.text))}</div>
        <div class="timer-wrap">
          <div class="ring" id="ring" style="--deg:360deg"><span id="ring-num">${q.time||20}</span></div>
          <div class="timer-bar" id="tbar"><i id="tbar-fill" style="width:100%"></i></div>
        </div>
        <div class="type-input-wrap">
          <input id="type-input" class="input type-input" placeholder="${esc(q.type==="num"?t("num_answer_ph"):t("type_answer_ph"))}" ${q.type==="num"?'inputmode="decimal"':""} maxlength="90" autocomplete="off" autocapitalize="off" spellcheck="false">
          <button class="btn btn-primary type-submit" data-action="typesubmit">${esc(t("type_submit"))} ▶</button>
        </div>
      </div>`;
    } else {
      const two=q.type==="tf";
      const ans=q.answers.map((a,i)=>`
        <button class="ans ${ANSCLASS[i]}" data-action="answer" data-i="${i}">
          <span class="ico">${SHAPES[i]}</span>
          <span>${esc(L(a.text))}</span>
          <span class="mark"></span>
        </button>`).join("");
      main=`<div class="q-area">
        <div class="q-card">${esc(L(q.text))}</div>
        <div class="timer-wrap">
          <div class="ring" id="ring" style="--deg:360deg"><span id="ring-num">${q.time||20}</span></div>
          <div class="timer-bar" id="tbar"><i id="tbar-fill" style="width:100%"></i></div>
        </div>
        <div class="hint-row">${esc(t("keys_hint"))}</div>
        <div class="answers ${two?"two":""}">${ans}</div>
      </div>`;
    }
  } else if(p.phase==="reveal"){
    main=revealView();
  } else if(p.phase==="scoreboard"){
    main=scoreboardView();
  } else if(p.phase==="podium"){
    main=podiumView();
  }
  return `<div class="stage">${strip}<div class="stage-main">${main}</div></div>`;
}

function revealView(){
  const p=state.play; const q=curQuestion();
  const correctIdx=q.answers.findIndex(a=>a.correct);
  const correctTxt=correctIdx>=0?L(q.answers[correctIdx].text):"";
  if(p.mode==="solo"){
    const r=p.roundResults[0];
    const cls=r.correct?"ok":"no";
    const verdict=r.correct?t("verdict_ok"):(r.answered?t("verdict_no"):t("verdict_miss"));
    return `<div class="reveal">
      <div class="verdict ${cls}">${r.correct?"✓":"✗"} ${esc(verdict)}</div>
      ${r.correct?`<div class="points">+${r.pts}</div>`:`<div class="correct-was">${esc(t("correct_was"))} <b>${esc(correctTxt)}</b></div>`}
      ${(q.type==="type"||q.type==="num")&&r.answered&&!r.correct?`<div class="you-wrote">${esc(t("you_wrote"))} <b>${esc(r.text||"")}</b></div>`:""}
      ${r.correct&&r.streak>=2?`<div class="streak">${esc(t("streak",r.streak))}</div>`:""}
      <div style="margin-top:6px;color:var(--muted);font-weight:700">⭐ ${p.participants[0].score}</div>
      <button class="btn btn-primary btn-lg" style="margin-top:16px" data-action="continue">${esc(t("continue"))} ▶</button>
    </div>`;
  }
  // hotseat: show correct + each player's result
  const rows=p.roundResults.map(r=>`
    <div class="rr ${r.correct?"":"miss"}">
      <div class="av" style="background:${avColor(r.idx)}">${esc(initials(r.name))}</div>
      <div class="nm">${esc(r.name)}</div>
      <div class="pt">${r.correct?"+"+r.pts:(r.answered?"✗":"—")}</div>
    </div>`).join("");
  return `<div class="reveal">
    <div class="verdict ok" style="font-size:clamp(22px,5vw,34px);color:#fff">${esc(t("correct_was"))}</div>
    <div class="points" style="font-size:clamp(22px,5vw,34px)">${esc(correctTxt)}</div>
    <div class="reveal-results">${rows}</div>
    <button class="btn btn-primary btn-lg" style="margin-top:12px" data-action="continue">${esc(t("continue"))} ▶</button>
  </div>`;
}

function scoreboardView(){
  const p=state.play;
  const sorted=[...p.participants].sort((a,b)=>b.score-a.score);
  const rows=sorted.map((pt,i)=>{
    let mv="same", arrow="—";
    if(pt._preRank>pt._postRank){ mv="up"; arrow="▲"+(pt._preRank-pt._postRank); }
    else if(pt._preRank<pt._postRank){ mv="down"; arrow="▼"+(pt._postRank-pt._preRank); }
    return `<div class="sb-row" style="animation-delay:${i*0.06}s">
      <div class="rk">${i+1}</div>
      <div class="av" style="background:${avColor(pt.idx)}">${esc(initials(pt.name))}</div>
      <div class="nm">${esc(pt.name)}</div>
      <div class="mv ${mv}">${arrow}</div>
      <div class="sc">${pt.score}</div>
    </div>`;
  }).join("");
  return `<div class="scoreboard">
    <h2 style="text-align:center;margin:0 0 6px">${esc(t("scoreboard"))}</h2>
    ${rows}
    <button class="btn btn-primary btn-lg btn-block" style="margin-top:14px;max-width:300px;margin-left:auto;margin-right:auto" data-action="continue">${esc(t("continue"))} ▶</button>
  </div>`;
}

function podiumView(){
  const p=state.play;
  const sorted=[...p.participants].sort((a,b)=>b.score-a.score);
  if(p.mode==="solo"){
    const me=p.participants[0]; const total=p.quiz.questions.length;
    const acc=Math.round(me.correct/total*100);
    return `<div class="podium-screen">
      <h2>🎉 ${esc(L(p.quiz.title))}</h2>
      <div class="pod p1" style="max-width:200px">
        <div class="av" style="background:${avColor(0)}">${esc(initials(me.name))}</div>
        <div class="nm">${esc(me.name)}</div>
        <div class="bar" style="height:90px">⭐</div>
      </div>
      <div class="solo-stats">
        <div class="stat"><div class="v">${me.score}</div><div class="l">${esc(t("stat_pts"))}</div></div>
        <div class="stat"><div class="v">${me.correct}/${total}</div><div class="l">${esc(t("stat_correct"))}</div></div>
        <div class="stat"><div class="v">${acc}%</div><div class="l">${esc(t("stat_acc"))}</div></div>
        <div class="stat"><div class="v">${me.best}</div><div class="l">${esc(t("stat_streak"))}</div></div>
      </div>
      <div class="cta-row">
        <button class="btn btn-primary btn-lg" data-action="playagain">↻ ${esc(t("play_again"))}</button>
        <button class="btn btn-ghost btn-lg" data-action="fbfromquiz">💬 ${esc(t("fb_leave"))}</button>
        <button class="btn btn-ghost btn-lg" data-action="gohome">${esc(t("back_home"))}</button>
      </div>
    </div>`;
  }
  const top=sorted.slice(0,3);
  const podHtml=top.map((pt,i)=>{
    const place=i+1;
    return `<div class="pod p${place}" style="animation-delay:${0.2*(3-place)}s">
      <div class="av" style="background:${avColor(pt.idx)}">${esc(initials(pt.name))}</div>
      <div class="nm">${esc(pt.name)}</div>
      <div class="sc">${pt.score}</div>
      <div class="bar">${place===1?"🥇":place===2?"🥈":"🥉"}</div>
    </div>`;
  }).join("");
  const rest=sorted.slice(3).map((pt,i)=>`
    <div class="sb-row" style="animation-delay:${i*0.05}s">
      <div class="rk">${i+4}</div>
      <div class="av" style="background:${avColor(pt.idx)}">${esc(initials(pt.name))}</div>
      <div class="nm">${esc(pt.name)}</div>
      <div class="sc">${pt.score}</div>
    </div>`).join("");
  return `<div class="podium-screen">
    <h2>🏆 ${esc(t("podium_title"))}</h2>
    <div style="text-align:center;color:var(--accent);font-weight:800;font-size:18px">${esc(t("winner_is"))}: ${esc(top[0].name)}</div>
    <div class="podium">${podHtml}</div>
    ${rest?`<div class="rest-list">${rest}</div>`:""}
    <div class="cta-row">
      <button class="btn btn-primary btn-lg" data-action="playagain">↻ ${esc(t("play_again"))}</button>
      <button class="btn btn-ghost btn-lg" data-action="fbfromquiz">💬 ${esc(t("fb_leave"))}</button>
        <button class="btn btn-ghost btn-lg" data-action="gohome">${esc(t("back_home"))}</button>
    </div>
  </div>`;
}

function bindPlay(){
  // keyboard answers
  document.onkeydown=(e)=>{
    const p=state.play; if(!p||p.phase!=="question"||p.answered) return;
    const q=curQuestion(); if(q.type==="type") return;
    const map={"1":0,"2":1,"3":2,"4":3,"q":0,"w":1,"e":2,"r":3};
    const k=e.key.toLowerCase();
    if(map[k]!=null && map[k]<q.answers.length){ e.preventDefault(); pressAnswer(map[k]); }
  };
  const p=state.play;
  if(p&&p.phase==="question"&&!p.answered){ const q=curQuestion(); if(q&&q.type==="type"){ const inp=document.getElementById("type-input"); if(inp){ inp.focus(); inp.onkeydown=(e)=>{ if(e.key==="Enter"){ e.preventDefault(); const v=(inp.value||"").trim(); if(v) submitTextAnswer(v); else toast(t("type_need_answer")); } }; } } }
}
function pressAnswer(i){
  const p=state.play; if(p.answered) return;
  const q=curQuestion(); if(q.type==="type") return;
  // visual lock + reveal for solo, just lock for hotseat
  const btns=document.querySelectorAll(".answers .ans");
  btns.forEach((b,j)=>{ b.setAttribute("disabled","");
    if(p.mode==="solo"){
      if(q.answers[j].correct){ b.classList.add("correct"); b.querySelector(".mark").textContent="✓"; }
      else if(j===i){ b.classList.add("wrong"); b.querySelector(".mark").textContent="✗"; }
      else b.classList.add("dim");
    } else {
      if(j===i){ const tag=document.createElement("span"); tag.className="picked-tag"; tag.textContent="✓"; b.appendChild(tag); }
      else b.classList.add("dim");
    }
  });
  // small delay so player sees feedback before screen changes
  setTimeout(()=>submitAnswer(i), p.mode==="solo"?850:450);
}

/* ---------------- MODAL (settings) ---------------- */
function renderModal(){
  let m=document.getElementById("modal-layer");
  if(!state.modal){ if(m) m.remove(); return; }
  if(!m){ m=document.createElement("div"); m.id="modal-layer"; document.body.appendChild(m); }
  if(state.modal==="settings"){
    m.innerHTML=`<div class="modal-bg" data-action="closemodal-bg">
      <div class="modal" data-stop="1">
        <h3>⚙️ ${esc(t("set_title"))}</h3>
        <div class="set-row">
          <div><div class="t">${esc(t("set_lang"))}</div><div class="d">${esc(t("set_lang_d"))}</div></div>
          <div class="langtog">
            <button class="${state.lang==="ro"?"on":""}" data-action="lang" data-v="ro">RO</button>
            <button class="${state.lang==="en"?"on":""}" data-action="lang" data-v="en">EN</button>
        <button class="${state.lang==="fr"?"on":""}" data-action="lang" data-v="fr">FR</button>
        <button class="${state.lang==="it"?"on":""}" data-action="lang" data-v="it">IT</button>
        <button class="${state.lang==="es"?"on":""}" data-action="lang" data-v="es">ES</button>
        <button class="${state.lang==="pt"?"on":""}" data-action="lang" data-v="pt">PT</button>
        <button class="${state.lang==="de"?"on":""}" data-action="lang" data-v="de">DE</button>
          </div>
        </div>
        <div class="set-row">
          <div><div class="t">${esc(t("set_sound"))}</div><div class="d">${esc(t("set_sound_d"))}</div></div>
          <div class="switch ${state.sound?"on":""}" data-action="sound"></div>
        </div>
        ${state.server?`<div class="set-row"><div><div class="t">${esc(t("set_admin"))}</div><div class="d">${esc(state.admin?t("set_admin_on"):t("set_admin_off"))}</div></div>${state.admin?`<button class="btn btn-ghost mini" style="width:auto;padding:8px 14px" data-action="dologout">${esc(t("logout"))}</button>`:`<button class="btn btn-ghost mini" style="width:auto;padding:8px 14px" data-action="openlogin">${esc(t("login"))}</button>`}</div>`:""}
        <div class="about">${esc(t("set_about"))}</div>
        <button class="btn btn-primary btn-block" style="margin-top:18px" data-action="closemodal">${esc(t("close"))}</button>
      </div></div>`;
  }
  if(state.modal==="login"){
    const setup = state.server && !QFF.adminExists;
    m.innerHTML=`<div class="modal-bg" data-action="closemodal-bg"><div class="modal" data-stop="1">
      <h3>🔐 ${esc(t(setup?"admin_setup":"admin_login"))}</h3>
      <div class="about" style="margin:0 0 12px">${esc(t(setup?"admin_setup_d":"admin_login_d"))}</div>
      <input id="adm-pw" class="input" type="password" placeholder="${esc(t("password"))}" autocomplete="current-password">
      ${setup?`<input id="adm-pw2" class="input" type="password" placeholder="${esc(t("password_again"))}" style="margin-top:8px" autocomplete="new-password">`:""}
      <button class="btn btn-primary btn-block" style="margin-top:14px" data-action="${setup?"dosetup":"dologin"}">${esc(t(setup?"admin_create":"login"))}</button>
      <button class="btn btn-ghost btn-block" style="margin-top:8px" data-action="closemodal">${esc(t("close"))}</button>
    </div></div>`;
  }
}

/* ---------------- FEEDBACK / GUESTBOOK ---------------- */
function openGuestbook(ctx){
  state.fbCtx=ctx||null; state.gbScope="all"; state.fbForm={rating:0,quizId:ctx?ctx.id:""}; state.feedback=[];
  state.screen="guestbook"; render();
  Store.loadFeedback("all").then(f=>{ state.feedback=f; if(state.screen==="guestbook") render(); }).catch(()=>{});
}
function syncFbForm(){ const ff=state.fbForm||(state.fbForm={}); const nm=document.getElementById("fb-name"); if(nm)ff.name=nm.value; const ms=document.getElementById("fb-msg"); if(ms)ff.msg=ms.value; const qs=document.getElementById("fb-quiz"); if(qs)ff.quizId=qs.value; const hp=document.getElementById("fb-hp"); if(hp)ff.hp=hp.value; }
function submitFeedback(){
  const ff=state.fbForm||{}; syncFbForm();
  const rating=ff.rating||0; const msg=(ff.msg||"").trim();
  if(rating<1){ toast(t("fb_need_rating")); return; }
  if(!msg){ toast(t("fb_need_msg")); return; }
  const quiz=state.quizzes.find(x=>x.id===(ff.quizId||""));
  const entry={ name:(ff.name||"").trim().slice(0,60), message:msg.slice(0,2000), rating:rating, quizId:ff.quizId||"", quizTitle:quiz?L(quiz.title):"" };
  if(ff.hp) entry.website=ff.hp;
  state.fbBusy=true; render();
  Store.addFeedback(entry).then(saved=>{
    state.fbForm={rating:0,quizId:state.fbCtx?state.fbCtx.id:""}; state.fbBusy=false;
    toast(saved&&saved.status==="pend"?t("fb_thanks_mod"):t("fb_thanks"));
    return Store.loadFeedback("all");
  }).then(f=>{ if(f) state.feedback=f; render(); }).catch(e=>{ state.fbBusy=false; toast(apiErr(e)); render(); });
}
function doLogin(){ const pw=(document.getElementById("adm-pw")||{}).value||""; if(!pw){ toast(t("err_pw")); return; }
  Store.adminLogin(pw).then(()=>{ QFF.adminExists=true; state.modal=null; renderModal(); render(); toast(t("admin_ok")); })
    .catch(e=>{ toast((e.status===401||e.status===409)?t("admin_bad"):apiErr(e)); }); }
function doSetup(){ const pw=(document.getElementById("adm-pw")||{}).value||""; const pw2=(document.getElementById("adm-pw2")||{}).value||"";
  if(pw.length<6){ toast(t("err_pw_short")); return; } if(pw!==pw2){ toast(t("err_pw_match")); return; }
  Store.adminSetup(pw).then(()=>{ QFF.adminExists=true; state.modal=null; renderModal(); render(); toast(t("admin_ok")); }).catch(e=>{ toast(apiErr(e)); }); }
function doLogout(){ Store.adminLogout().then(()=>{ state.modal=null; renderModal(); render(); toast(t("admin_out")); }).catch(()=>{ state.modal=null; renderModal(); render(); }); }

function viewGuestbook(){
  const f=state.feedback||[];
  const list=f.length? f.map(fbCard).join("") : `<div class="card-empty">${esc(t("fb_empty"))}</div>`;
  return `<div class="wrap">
    <a class="backlink" data-action="home">← ${esc(t("back_home"))}</a>
    <div class="page-head"><div><h2>${esc(t("fb_title"))}</h2><div class="sub">${esc(t("fb_sub"))}</div></div></div>
    ${renderFbForm()}
    <div class="page-head" style="margin:20px 0 10px"><h2 style="font-size:18px">${esc(t("fb_recent"))}</h2>${state.server?"":`<span class="hint">${esc(t("fb_local_note"))}</span>`}</div>
    <div class="gb-list">${list}</div>
  </div>`;
}
function renderFbForm(){
  const ff=state.fbForm||(state.fbForm={rating:0,quizId:(state.fbCtx?state.fbCtx.id:"")});
  const opts=`<option value="">${esc(t("fb_pick_quiz"))}</option>`+state.quizzes.map(q=>`<option value="${esc(q.id)}"${ff.quizId===q.id?" selected":""}>${esc(L(q.title))}</option>`).join("");
  const stars=[1,2,3,4,5].map(n=>`<button type="button" class="star${n<=(ff.rating||0)?" on":""}" data-action="fbstar" data-v="${n}" aria-label="${n}/5">★</button>`).join("");
  return `<div class="panel fb-form">
    <div class="row">
      <div class="field" style="margin-bottom:0"><label>${esc(t("fb_name"))}</label>
        <input id="fb-name" class="input" maxlength="60" placeholder="${esc(t("fb_name_ph"))}" value="${esc(ff.name||"")}"></div>
      <div class="field" style="margin-bottom:0"><label>${esc(t("fb_quiz"))}</label>
        <select id="fb-quiz" class="select">${opts}</select></div>
    </div>
    <div class="field" style="margin:14px 0 0"><label>${esc(t("fb_rating"))}</label>
      <div class="stars" id="fb-stars">${stars}</div></div>
    <div class="field" style="margin:14px 0 0"><label>${esc(t("fb_msg"))}</label>
      <textarea id="fb-msg" class="textarea" rows="3" maxlength="2000" placeholder="${esc(t("fb_msg_ph"))}">${esc(ff.msg||"")}</textarea></div>
    <input id="fb-hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
    <button class="btn btn-primary btn-block" style="margin-top:16px" data-action="fbsubmit"${state.fbBusy?" disabled":""}>${state.fbBusy?"…":"✓ "+esc(t("fb_send"))}</button>
  </div>`;
}
function fbCard(e){
  const r=Math.max(0,Math.min(5,e.rating|0));
  const stars="★".repeat(r)+"☆".repeat(5-r);
  const pend=(e.status==="pend");
  return `<div class="gb-card${pend?" pend":""}">
    <div class="gb-top">
      <div class="gb-who">${esc(e.name||t("fb_anon"))}${pend?` <span class="gb-pend">${esc(t("fb_pending"))}</span>`:""}</div>
      <div class="gb-stars" title="${r}/5">${stars}</div>
    </div>
    ${e.quizTitle?`<div class="gb-quiz">📋 ${esc(e.quizTitle)}</div>`:""}
    <div class="gb-msg">${esc(e.message)}</div>
    <div class="gb-foot"><span class="gb-when">${esc(fmtDate(e.ts))}</span>
      ${state.admin?`<span class="gb-act">${pend?`<button class="btn btn-ghost mini" style="width:auto;padding:6px 12px" data-action="fbapprove" data-ts="${esc(String(e.ts))}">✓ ${esc(t("fb_approve"))}</button>`:""}<button class="btn btn-ghost mini" style="width:auto;padding:6px 12px" data-action="fbdelete" data-ts="${esc(String(e.ts))}">🗑</button></span>`:""}
    </div>
  </div>`;
}


/* ============================ QR ENCODER (validated) ============================ */
const QR_RS={1:{L:{ec:7,g:[[1,19]]},M:{ec:10,g:[[1,16]]},Q:{ec:13,g:[[1,13]]},H:{ec:17,g:[[1,9]]}},2:{L:{ec:10,g:[[1,34]]},M:{ec:16,g:[[1,28]]},Q:{ec:22,g:[[1,22]]},H:{ec:28,g:[[1,16]]}},3:{L:{ec:15,g:[[1,55]]},M:{ec:26,g:[[1,44]]},Q:{ec:18,g:[[2,17]]},H:{ec:22,g:[[2,13]]}},4:{L:{ec:20,g:[[1,80]]},M:{ec:18,g:[[2,32]]},Q:{ec:26,g:[[2,24]]},H:{ec:16,g:[[4,9]]}},5:{L:{ec:26,g:[[1,108]]},M:{ec:24,g:[[2,43]]},Q:{ec:18,g:[[2,15],[2,16]]},H:{ec:22,g:[[2,11],[2,12]]}},6:{L:{ec:18,g:[[2,68]]},M:{ec:16,g:[[4,27]]},Q:{ec:24,g:[[4,19]]},H:{ec:28,g:[[4,15]]}},7:{L:{ec:20,g:[[2,78]]},M:{ec:18,g:[[4,31]]},Q:{ec:18,g:[[2,14],[4,15]]},H:{ec:26,g:[[4,13],[1,14]]}},8:{L:{ec:24,g:[[2,97]]},M:{ec:22,g:[[2,38],[2,39]]},Q:{ec:22,g:[[4,18],[2,19]]},H:{ec:26,g:[[4,14],[2,15]]}},9:{L:{ec:30,g:[[2,116]]},M:{ec:22,g:[[3,36],[2,37]]},Q:{ec:20,g:[[4,16],[4,17]]},H:{ec:24,g:[[4,12],[4,13]]}},10:{L:{ec:18,g:[[2,68],[2,69]]},M:{ec:26,g:[[4,43],[1,44]]},Q:{ec:24,g:[[6,19],[2,20]]},H:{ec:28,g:[[6,15],[2,16]]}}};
const QR_ALIGN={1:[],2:[6,18],3:[6,22],4:[6,26],5:[6,30],6:[6,34],7:[6,22,38],8:[6,24,42],9:[6,26,46],10:[6,28,50]};
/* Compact QR encoder (byte mode), structure ported from Nayuki's public-domain
   reference, using exact RS-block + alignment tables extracted from the spec. */

/* ---- GF(256) ---- */
const QR_EXP=new Array(512), QR_LOG=new Array(256);
(function(){ let x=1; for(let i=0;i<255;i++){ QR_EXP[i]=x; QR_LOG[x]=i; x<<=1; if(x&0x100) x^=0x11d; } for(let i=255;i<512;i++) QR_EXP[i]=QR_EXP[i-255]; })();
function qrMul(a,b){ return (a===0||b===0)?0:QR_EXP[QR_LOG[a]+QR_LOG[b]]; }
function qrGenPoly(deg){ let r=[1]; for(let i=0;i<deg;i++){ const n=new Array(r.length+1).fill(0); for(let j=0;j<r.length;j++){ n[j]^=r[j]; n[j+1]^=qrMul(r[j],QR_EXP[i]); } r=n; } return r.slice(1); }
function qrEcc(data,ecLen){ const gen=qrGenPoly(ecLen); const res=new Array(ecLen).fill(0);
  for(let i=0;i<data.length;i++){ const f=data[i]^res[0]; res.shift(); res.push(0); if(f!==0) for(let j=0;j<ecLen;j++) res[j]^=qrMul(gen[j],f); }
  return res; }

function qrTotalData(v,lvl){ let s=0; for(const [c,d] of QR_RS[v][lvl].g) s+=c*d; return s; }
function qrPickVersion(len,lvl){ for(let v=1;v<=10;v++){ const cc=v<=9?8:16; const need=Math.ceil((4+cc+8*len)/8); if(need<=qrTotalData(v,lvl)) return v; } return -1; }

function qrEncodeData(bytes,v,lvl){
  const cap=qrTotalData(v,lvl)*8; const bits=[];
  const push=(val,n)=>{ for(let i=n-1;i>=0;i--) bits.push((val>>i)&1); };
  push(0b0100,4);
  push(bytes.length, v<=9?8:16);
  for(const b of bytes) push(b,8);
  push(0, Math.min(4, cap-bits.length));
  while(bits.length%8!==0) bits.push(0);
  const pad=[0xEC,0x11]; let pi=0;
  while(bits.length<cap){ push(pad[pi],8); pi^=1; }
  const cw=[]; for(let i=0;i<bits.length;i+=8){ let b=0; for(let j=0;j<8;j++) b=(b<<1)|bits[i+j]; cw.push(b); }
  return cw;
}

function qrInterleave(dataCw,v,lvl){
  const ec=QR_RS[v][lvl].ec; const blocks=[]; let idx=0, maxData=0;
  for(const [cnt,dlen] of QR_RS[v][lvl].g){ for(let k=0;k<cnt;k++){ const dat=dataCw.slice(idx,idx+dlen); idx+=dlen; maxData=Math.max(maxData,dlen); blocks.push({d:dat,e:qrEcc(dat,ec)}); } }
  const out=[];
  for(let i=0;i<maxData;i++) for(const b of blocks) if(i<b.d.length) out.push(b.d[i]);
  for(let i=0;i<ec;i++) for(const b of blocks) out.push(b.e[i]);
  return out;
}

function qrBuild(text,lvl,forceV,forceMask){
  const bytes=Array.from(new TextEncoder().encode(text));
  const v=forceV||qrPickVersion(bytes.length,lvl);
  if(v<0) throw new Error("too long");
  const size=v*4+17;
  const mod=Array.from({length:size},()=>new Array(size).fill(false));
  const fn =Array.from({length:size},()=>new Array(size).fill(false));
  const setF=(x,y,d)=>{ if(x<0||y<0||x>=size||y>=size) return; mod[y][x]=d; fn[y][x]=true; };
  const getBit=(x,i)=>((x>>i)&1)!==0;

  // timing (drawn first; finders overwrite the overlap)
  for(let i=0;i<size;i++){ setF(6,i,i%2===0); setF(i,6,i%2===0); }
  // finders
  for(const [cx,cy] of [[3,3],[size-4,3],[3,size-4]]){
    for(let dy=-4;dy<=4;dy++) for(let dx=-4;dx<=4;dx++){ const dist=Math.max(Math.abs(dx),Math.abs(dy)); setF(cx+dx,cy+dy, dist!==2&&dist!==4); }
  }
  // alignment
  const ap=QR_ALIGN[v]||[]; const na=ap.length;
  for(let i=0;i<na;i++) for(let j=0;j<na;j++){
    if((i===0&&j===0)||(i===0&&j===na-1)||(i===na-1&&j===0)) continue;
    const ax=ap[i], ay=ap[j];
    for(let dy=-2;dy<=2;dy++) for(let dx=-2;dx<=2;dx++) setF(ax+dx,ay+dy, Math.max(Math.abs(dx),Math.abs(dy))!==1);
  }
  // dark module
  setF(8,size-8,true);

  const eccFmt={L:1,M:0,Q:3,H:2}[lvl];
  function drawFormat(mask){
    let data=(eccFmt<<3)|mask, rem=data;
    for(let i=0;i<10;i++) rem=(rem<<1)^(((rem>>9)&1)*0x537);
    const bits=((data<<10)|rem)^0x5412;
    for(let i=0;i<=5;i++) setF(8,i,getBit(bits,i));
    setF(8,7,getBit(bits,6)); setF(8,8,getBit(bits,7)); setF(7,8,getBit(bits,8));
    for(let i=9;i<=14;i++) setF(14-i,8,getBit(bits,i));
    for(let i=0;i<=7;i++) setF(size-1-i,8,getBit(bits,i));
    for(let i=8;i<=14;i++) setF(8,size-15+i,getBit(bits,i));
    setF(8,size-8,true);
  }
  function drawVersion(){
    if(v<7) return;
    let rem=v; for(let i=0;i<12;i++) rem=(rem<<1)^(((rem>>11)&1)*0x1F25);
    const bits=(v<<12)|rem;
    for(let i=0;i<18;i++){ const b=getBit(bits,i); const a=size-11+i%3, c=Math.floor(i/3); setF(a,c,b); setF(c,a,b); }
  }
  drawFormat(0); drawVersion();

  // data placement
  const cw=qrInterleave(qrEncodeData(bytes,v,lvl),v,lvl);
  const bitArr=[]; for(const b of cw) for(let i=7;i>=0;i--) bitArr.push((b>>i)&1);
  let bi=0;
  for(let right=size-1; right>=1; right-=2){
    if(right===6) right=5;
    for(let vert=0; vert<size; vert++){
      for(let k=0;k<2;k++){
        const x=right-k;
        const upward=((right+1)&2)===0;
        const y=upward?(size-1-vert):vert;
        if(!fn[y][x]){ let dark=false; if(bi<bitArr.length){ dark=bitArr[bi]===1; bi++; } mod[y][x]=dark; }
      }
    }
  }

  function maskFn(m,x,y){ switch(m){
    case 0: return (x+y)%2===0; case 1: return y%2===0; case 2: return x%3===0;
    case 3: return (x+y)%3===0; case 4: return (Math.floor(y/2)+Math.floor(x/3))%2===0;
    case 5: return (x*y)%2+(x*y)%3===0; case 6: return ((x*y)%2+(x*y)%3)%2===0;
    case 7: return ((x+y)%2+(x*y)%3)%2===0; } return false; }

  function applyMask(m){ for(let y=0;y<size;y++) for(let x=0;x<size;x++) if(!fn[y][x] && maskFn(m,x,y)) mod[y][x]=!mod[y][x]; }

  function penalty(){
    let p=0;
    // rule1 rows+cols
    for(let y=0;y<size;y++){ let run=1; for(let x=1;x<size;x++){ if(mod[y][x]===mod[y][x-1]){ run++; if(run===5)p+=3; else if(run>5)p++; } else run=1; } }
    for(let x=0;x<size;x++){ let run=1; for(let y=1;y<size;y++){ if(mod[y][x]===mod[y-1][x]){ run++; if(run===5)p+=3; else if(run>5)p++; } else run=1; } }
    // rule2 2x2
    for(let y=0;y<size-1;y++) for(let x=0;x<size-1;x++){ const c=mod[y][x]; if(c===mod[y][x+1]&&c===mod[y+1][x]&&c===mod[y+1][x+1]) p+=3; }
    // rule3 finder-like patterns
    const pat=[true,false,true,true,true,false,true];
    function check(get){ for(let y=0;y<size;y++) for(let x=0;x<size;x++){ // horizontal
      } }
    for(let y=0;y<size;y++) for(let x=0;x<size;x++){
      // horizontal
      if(x+11<=size){ let ok1=true; for(let i=0;i<7;i++) if(mod[y][x+i]!==pat[i]) ok1=false; let lz=true; for(let i=7;i<11;i++) if(mod[y][x+i]!==false) lz=false; let lzb=true; for(let i=-4;i<0;i++){ if(x+i<0||mod[y][x+i]!==false) lzb=false; } if(ok1&&(lz||lzb)) p+=40; }
      if(y+11<=size){ let ok1=true; for(let i=0;i<7;i++) if(mod[y+i][x]!==pat[i]) ok1=false; let lz=true; for(let i=7;i<11;i++) if(mod[y+i][x]!==false) lz=false; let lzb=true; for(let i=-4;i<0;i++){ if(y+i<0||mod[y+i][x]!==false) lzb=false; } if(ok1&&(lz||lzb)) p+=40; }
    }
    // rule4 dark ratio
    let dark=0; for(let y=0;y<size;y++) for(let x=0;x<size;x++) if(mod[y][x]) dark++;
    const total=size*size; const ratio=dark*100/total;
    const k=Math.floor(Math.abs(ratio-50)/5); p+=k*10;
    return p;
  }

  let mask=forceMask;
  if(mask==null||mask<0){ let best=1e9; for(let m=0;m<8;m++){ applyMask(m); drawFormat(m); const pen=penalty(); if(pen<best){ best=pen; mask=m; } applyMask(m); } }
  applyMask(mask); drawFormat(mask);
  return {size, modules:mod, version:v, mask};
}

/* ---- QR canvas renderer ---- */
function qrCanvas(text, scale, quiet){
  scale=scale||6; quiet=(quiet==null?4:quiet);
  const q=qrBuild(text,"M"); const n=q.size; const dim=(n+quiet*2)*scale;
  const c=document.createElement("canvas"); c.width=dim; c.height=dim;
  const ctx=c.getContext("2d");
  ctx.fillStyle="#ffffff"; ctx.fillRect(0,0,dim,dim);
  ctx.fillStyle="#1b0b3a";
  for(let y=0;y<n;y++) for(let x=0;x<n;x++) if(q.modules[y][x]) ctx.fillRect((x+quiet)*scale,(y+quiet)*scale,scale,scale);
  return c;
}
function qrInto(el,text,scale){ el.innerHTML=""; try{ const c=qrCanvas(text,scale||6,4); c.style.width="100%"; c.style.height="auto"; c.style.maxWidth="240px"; c.style.imageRendering="pixelated"; c.style.borderRadius="12px"; c.style.display="block"; el.appendChild(c); }catch(e){ el.textContent=text; } }

/* ============================================================================
   LIVE AUDIENCE MODE — multi-device word cloud / poll / Q&A (server-backed)
   ========================================================================== */
Object.assign(state, { live:null, liveRole:null, join:null,
  liveBuilder:{ type:"cloud", prompt:"", options:["",""], statements:["",""], multi:1, scale:5, budget:100, mod:false, filter:false }, liveMode:"single", deck:{ title:"", slides:[] }, _poll:null });

const CLOUD_COLORS=["#ffd23f","#ff5a5f","#34d3ff","#18bd6b","#b06bff","#ff8c42","#ff6ad5","#9fe84f","#62d0ff","#ffc14d"];
const POLL_COLORS=["#2f6bff","#ff5a5f","#ffd23f","#18bd6b","#b06bff","#ff8c42","#34d3ff","#ff6ad5"];
const FONT_STACK='ui-rounded, "SF Pro Rounded", "Segoe UI", system-ui, -apple-system, Roboto, sans-serif';

function startPoll(fn){ stopPoll(); state._poll=setInterval(fn, 2200); }
function stopPoll(){ if(state._poll){ clearInterval(state._poll); state._poll=null; } if(state._tick){ clearInterval(state._tick); state._tick=null; } }
function liveDL(blob,name){ const u=URL.createObjectURL(blob); const a=document.createElement("a"); a.href=u; a.download=name; document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(u),1500); }
function joinURLFor(code){ return location.origin+location.pathname+"?join="+code; }

/* ---------- word-cloud layout (spiral + bbox collision) ---------- */
let _measCtx=null;
function measCtx(){ if(!_measCtx){ const c=document.createElement("canvas"); _measCtx=c.getContext("2d"); } return _measCtx; }
function cloudFont(fs){ return "800 "+fs+"px "+FONT_STACK; }
function layoutCloud(words, W, H){
  if(!words || !words.length) return {placements:[],W:W,H:H};
  words=words.slice(0,110);
  const counts=words.map(w=>w.count); const maxC=Math.max.apply(null,counts), minC=Math.min.apply(null,counts);
  const minFs=Math.max(13, Math.round(Math.min(W,H)/20));
  const maxFs=Math.max(minFs+6, Math.round(Math.min(W*0.78, H/3.1)));
  const ctx=measCtx(); const placed=[]; const cx=W/2, cy=H/2; const out=[]; let idx=0;
  const aspect=W/H;
  function fsFor(c){ if(maxC===minC) return Math.round((minFs+maxFs)/2); const tt=(c-minC)/(maxC-minC); return Math.round(minFs+Math.pow(tt,0.72)*(maxFs-minFs)); }
  for(const w of words){
    const fs=fsFor(w.count); ctx.font=cloudFont(fs);
    const tw=ctx.measureText(w.text).width; const th=fs*1.0;
    const hw=tw/2+5, hh=th/2+4;
    let ok=false, px=cx, py=cy, t=0;
    for(let k=0;k<3000;k++){
      const r=2.3*t; px=cx+r*Math.cos(t); py=cy+(r*Math.sin(t))/aspect; t+=0.32;
      const x0=px-hw,y0=py-hh,x1=px+hw,y1=py+hh;
      if(x0<3||y0<3||x1>W-3||y1>H-3) continue;
      let hit=false; for(let p=0;p<placed.length;p++){ const q=placed[p]; if(x0<q.x1&&x1>q.x0&&y0<q.y1&&y1>q.y0){ hit=true; break; } }
      if(!hit){ ok=true; break; }
    }
    if(!ok) continue;
    placed.push({x0:px-hw,y0:py-hh,x1:px+hw,y1:py+hh});
    out.push({text:w.text,count:w.count,x:px,y:py,fs:fs,color:CLOUD_COLORS[idx%CLOUD_COLORS.length]});
    idx++;
  }
  return {placements:out, W:W, H:H};
}
function updateCloudDOM(container, lay){
  let map=container._cw; if(!map){ map=new Map(); container._cw=map; }
  const seen=new Set();
  for(const p of lay.placements){ seen.add(p.text); let s=map.get(p.text);
    if(!s){ s=document.createElement("span"); s.className="cloud-word"; s.textContent=p.text; s.style.opacity="0"; container.appendChild(s); map.set(p.text,s); }
    s.style.left=p.x+"px"; s.style.top=p.y+"px"; s.style.fontSize=p.fs+"px"; s.style.color=p.color; s.title=p.count+"×";
    (function(el){ requestAnimationFrame(()=>{ el.style.opacity="1"; }); })(s);
  }
  for(const [k,s] of map){ if(!seen.has(k)){ s.remove(); map.delete(k); } }
}
function cloudPNG(lay){
  const W=lay.W,H=lay.H,dpr=2;
  const c=document.createElement("canvas"); c.width=W*dpr; c.height=H*dpr; const ctx=c.getContext("2d"); ctx.scale(dpr,dpr);
  const ink=(getComputedStyle(document.documentElement).getPropertyValue('--ink')||"#16092e").trim()||"#16092e";
  ctx.fillStyle=ink; ctx.fillRect(0,0,W,H); ctx.textAlign="center"; ctx.textBaseline="middle";
  for(const p of lay.placements){ ctx.font=cloudFont(p.fs); ctx.fillStyle=p.color; ctx.fillText(p.text,p.x,p.y); }
  return c;
}

/* ---------- poll + Q&A rendering ---------- */
function updatePollViz(viz, results, total){
  if(viz._mode!=="poll"){ viz.innerHTML='<div class="poll-bars"></div>'; viz._mode="poll"; viz._cw=null; }
  const host=viz.querySelector(".poll-bars"); let rows=host._rows; if(!rows){ rows=[]; host._rows=rows; }
  (results||[]).forEach((r,i)=>{
    let row=rows[i];
    if(!row){ row=document.createElement("div"); row.className="poll-row";
      row.innerHTML='<div class="poll-top"><span class="poll-label"></span><span class="poll-num"></span></div><div class="poll-track"><i></i></div>';
      row.querySelector(".poll-track i").style.background=POLL_COLORS[i%POLL_COLORS.length];
      host.appendChild(row); rows[i]=row; }
    const pct=total>0?Math.round(r.count*100/total):0;
    row.querySelector(".poll-label").textContent=r.label;
    row.querySelector(".poll-num").textContent=r.count+" · "+pct+"%";
    row.querySelector(".poll-track i").style.width=pct+"%";
  });
}
function renderQAList(results, host, voted){
  const arr=results||[]; const pend = host ? arr.filter(r=>r.pending).length : 0;
  const out=arr.map(r=>{
    const didVote=voted&&voted.has(r.id);
    return '<div class="qa-card'+(r.hidden?' is-hidden':'')+(r.starred?' is-star':'')+(r.answered?' is-answered':'')+(r.pending?' is-pending':'')+'">'
      +'<button class="qa-up'+(didVote?' on':'')+'" '+(host?'disabled':('data-action="joinvote" data-id="'+esc(r.id)+'"'))+'>▲<b>'+(r.up||0)+'</b></button>'
      +'<div class="qa-body"><div class="qa-text">'+(r.pending?'<span class="qa-pend-badge">⏳ '+esc(t("qa_pending"))+'</span> ':'')+(r.starred?'<span class="qa-flag">★</span>':'')+(r.answered?'<span class="qa-ans-badge">✓ '+esc(t("qa_answered"))+'</span> ':'')+esc(r.text)+'</div>'+(r.name?'<div class="qa-name">— '+esc(r.name)+'</div>':'')+'</div>'
      +(host?('<div class="qa-admin">'
        +(r.pending
          ? '<button class="iconbtn sm approve" data-action="liveapprove" data-id="'+esc(r.id)+'" title="'+esc(t("qa_approve"))+'">✓ '+esc(t("qa_approve"))+'</button><button class="iconbtn sm" data-action="livedel" data-id="'+esc(r.id)+'" title="🗑">🗑</button>'
          : '<button class="iconbtn sm'+(r.starred?' on':'')+'" data-action="livestar" data-id="'+esc(r.id)+'" title="'+esc(t("qa_star"))+'">★</button>'
            +'<button class="iconbtn sm'+(r.answered?' on':'')+'" data-action="liveanswer" data-id="'+esc(r.id)+'" title="'+esc(t("qa_mark"))+'">✓</button>'
            +(r.hidden?'<button class="iconbtn sm" data-action="liveshow" data-id="'+esc(r.id)+'" title="'+esc(t("live_show"))+'">👁</button>':'<button class="iconbtn sm" data-action="livehide" data-id="'+esc(r.id)+'" title="'+esc(t("live_hide"))+'">🚫</button>')
            +'<button class="iconbtn sm" data-action="livedel" data-id="'+esc(r.id)+'" title="🗑">🗑</button>')
        +'</div>'):'')
      +'</div>';
  }).join("");
  const banner=(host&&pend>0)?'<div class="qa-pend-bar">⏳ '+esc(t("qa_pending_count",pend))+'</div>':'';
  return banner + (out || ('<div class="live-empty">'+esc(t("live_waiting"))+'</div>'));
}
function renderRating(s){
  const res=s.results||[], total=s.count||0, scale=s.scale||5, avg=s.average||0;
  const maxc=Math.max(1, ...res.map(r=>r.count));
  let head;
  if(scale===10){ head='<div class="rt-head"><div class="rt-big">'+(typeof s.nps==="number"?s.nps:0)+'</div><div class="rt-lbl">NPS · '+total+' '+esc(t("live_responses"))+'</div><div class="rt-sub"><span class="rt-prom">'+esc(t("rt_prom"))+' '+(s.promoters||0)+'</span> · <span class="rt-pass">'+esc(t("rt_pass"))+' '+(s.passives||0)+'</span> · <span class="rt-det">'+esc(t("rt_det"))+' '+(s.detractors||0)+'</span></div></div>'; }
  else { head='<div class="rt-head"><div class="rt-big">'+(Number(avg).toFixed(2))+' <span class="rt-star">★</span></div><div class="rt-lbl">'+esc(t("rt_avg"))+' · '+total+' '+esc(t("live_responses"))+'</div></div>'; }
  if(!total) return head+'<div class="live-empty">'+esc(t("live_waiting"))+'</div>';
  const bars=res.map(r=>{ const w=Math.round((r.count/maxc)*100), pct=total>0?Math.round(r.count*100/total):0;
    const lab=(scale===5)?('★'.repeat(r.value)):String(r.value);
    return '<div class="rt-row"><span class="rt-v">'+lab+'</span><span class="rt-bar"><i style="width:'+Math.max(2,w)+'%"></i></span><b class="rt-c">'+r.count+' · '+pct+'%</b></div>'; }).join("");
  return head+'<div class="rt-bars">'+bars+'</div>';
}
function renderRank(s){
  const res=s.results||[], total=s.count||0, n=res.length;
  if(!total) return '<div class="live-empty">'+esc(t("live_waiting"))+'</div>';
  const bars=res.map((r,i)=>{ const w=n>1?Math.round((n-r.avg)/(n-1)*100):100;
    return '<div class="rk-row"><span class="rk-pos">'+(i+1)+'</span><span class="rk-label">'+esc(r.label)+'</span><span class="rk-bar"><i style="width:'+Math.max(6,w)+'%"></i></span><b class="rk-avg">'+Number(r.avg).toFixed(2)+'</b></div>'; }).join("");
  return '<div class="rk-list">'+bars+'</div><div class="rk-foot">'+esc(t("rk_foot"))+' · '+total+' '+esc(t("live_responses"))+'</div>';
}
function renderScale(s){
  const res=s.results||[], total=s.count||0, max=s.scaleMax||5;
  if(!total) return '<div class="live-empty">'+esc(t("live_waiting"))+'</div>';
  const rows=res.map(r=>{ const w=Math.round((r.avg/max)*100);
    return '<div class="sc-row"><div class="sc-top"><span class="sc-stmt">'+esc(r.statement)+'</span><b class="sc-avg">'+Number(r.avg).toFixed(2)+'</b></div><div class="sc-track"><i style="width:'+Math.max(2,w)+'%"></i></div></div>'; }).join("");
  return '<div class="sc-list">'+rows+'</div><div class="sc-foot"><span>1 · '+esc(t("scale_lo"))+'</span><span>'+total+' '+esc(t("live_responses"))+'</span><span>'+esc(t("scale_hi"))+' · '+max+'</span></div>';
}
function renderPoints(s){
  const res=s.results||[], total=s.count||0, budget=s.budget||100;
  if(!total) return '<div class="live-empty">'+esc(t("live_waiting"))+'</div>';
  const maxTot=Math.max(1, ...res.map(r=>r.total));
  const rows=res.map(r=>{ const w=Math.round(r.total/maxTot*100);
    return '<div class="pt-row"><span class="pt-label">'+esc(r.label)+'</span><span class="pt-bar"><i style="width:'+Math.max(2,w)+'%"></i></span><b class="pt-val">'+r.total+' <span class="pt-pct">'+r.pct+'%</span></b></div>'; }).join("");
  return '<div class="pt-list">'+rows+'</div><div class="pt-foot">'+total+' '+esc(t("live_responses"))+' · '+esc(t("pt_budget_each",budget))+'</div>';
}
function renderResultsInto(viz, s, host, voted){
  const W=Math.max(300, viz.clientWidth||760), H=Math.max(220, viz.clientHeight||380);
  if(s.type==="cloud"){
    if(!s.results || !s.results.length){ viz.innerHTML='<div class="live-empty">'+esc(t("live_waiting"))+'</div>'; viz._cw=null; viz._mode="cloud0"; viz._lastLay=null; return; }
    if(viz._mode!=="cloud"){ viz.innerHTML=""; viz._cw=null; viz._mode="cloud"; }
    const lay=layoutCloud(s.results, W, H); viz._lastLay=lay; updateCloudDOM(viz, lay);
  } else if(s.type==="poll"){
    updatePollViz(viz, s.results, s.count);
  } else if(s.type==="rating"){
    viz._mode="rating"; viz._cw=null; viz.innerHTML=renderRating(s);
  } else if(s.type==="rank"){
    viz._mode="rank"; viz._cw=null; viz.innerHTML=renderRank(s);
  } else if(s.type==="scale"){
    viz._mode="scale"; viz._cw=null; viz.innerHTML=renderScale(s);
  } else if(s.type==="points"){
    viz._mode="points"; viz._cw=null; viz.innerHTML=renderPoints(s);
  } else {
    viz._mode="qa"; viz._cw=null; viz.innerHTML=renderQAList(s.results, host, voted);
  }
}

/* ---------- host (presenter) ---------- */
function updateLiveViz(){
  const s=state.live; if(!s) return;
  const cnt=document.getElementById("live-count"); if(cnt) cnt.textContent=s.count;
  const st=document.getElementById("live-status"); if(st){ st.textContent=s.open?t("live_open"):t("live_closed_b"); st.className="lh-status "+(s.open?"open":"closed"); }
  const viz=document.getElementById("live-viz"); if(viz) renderResultsInto(viz, s, true, null);
}
function refreshLive(){ const s=state.live; if(!s) return; api("live_get",{query:{code:s.code}}).then(d=>{ state.live=d.session; updateLiveViz(); }).catch(()=>{}); }
function liveStart(){
  if(!requireAdmin()) return;
  syncBuilder(); const lb=state.liveBuilder;
  if(!lb.prompt.trim()){ toast(t("live_need_prompt")); return; }
  let options=[]; if(lb.type==="poll"||lb.type==="rank"||lb.type==="points"){ options=(lb.options||[]).map(o=>o.trim()).filter(Boolean); if(options.length<2){ toast(t("live_need_opts")); return; } }
  let statements=[]; if(lb.type==="scale"){ statements=(lb.statements||[]).map(o=>o.trim()).filter(Boolean); if(statements.length<2){ toast(t("live_need_stmts")); return; } }
  api("live_create",{body:{ type:lb.type, prompt:lb.prompt.trim(), options:options, statements:statements, multi:lb.multi||1, scale:lb.scale||5, budget:lb.budget||100, mod:!!lb.mod, filter:!!lb.filter }})
    .then(d=>{ state.live=d.session; state.liveRole="host"; state.screen="livehost"; render(); startPoll(refreshLive); })
    .catch(e=>toast(apiErr(e)));
}
function liveControl(action,id){
  const s=state.live; if(!s) return;
  api("live_control",{body:{code:s.code, action:action, id:id}}).then(d=>{
    if(d.deleted){ stopPoll(); state.live=null; state.liveRole=null; state.screen="livehub"; render(); toast(t("live_ended")); return; }
    state.live=d.session; render();
  }).catch(e=>toast(apiErr(e)));
}
function copyJoin(){ const s=state.live; if(!s) return; const url=joinURLFor(s.code);
  const done=()=>toast(t("live_copied"));
  if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(url).then(done).catch(fallback); } else fallback();
  function fallback(){ const ta=document.createElement("textarea"); ta.value=url; ta.style.position="fixed"; ta.style.opacity="0"; document.body.appendChild(ta); ta.select(); try{document.execCommand("copy"); done();}catch(e){} ta.remove(); }
}
function shareJoin(){ const s=state.live; if(!s||!navigator.share) return; navigator.share({title:"Undava", text:s.prompt, url:joinURLFor(s.code)}).catch(()=>{}); }
function exportLiveJSON(){ const s=state.live; if(!s) return; liveDL(new Blob([JSON.stringify(s,null,2)],{type:"application/json"}), t("live_json_name")+"-"+s.code+".json"); }
function exportCloudPNG(){ const s=state.live; const viz=document.getElementById("live-viz"); if(!s||!viz) return; const lay=viz._lastLay; if(!lay||!lay.placements.length){ toast(t("live_waiting")); return; } cloudPNG(lay).toBlob(b=>{ if(b) liveDL(b, t("live_png_name")+"-"+s.code+".png"); }); }

function viewLiveHost(){
  const s=state.live; if(!s) return '<div class="wrap"><a class="backlink" data-action="livestop">←</a></div>';
  if(s.type==="game") return viewGameHost();
  if(s.type==="assign") return viewAssignHost();
  const tl={cloud:t("live_t_cloud"),poll:t("live_t_poll"),qa:t("live_t_qa"),rating:t("live_t_rating"),rank:t("live_t_rank"),scale:t("live_t_scale"),points:t("live_t_points")}[s.type];
  return '<div class="live-host">'
    +'<div class="lh-bar">'
      +'<a class="backlink" data-action="livestop">← '+esc(t("live_back"))+'</a>'
      +'<div class="lh-meta"><span class="lh-type">'+esc(tl)+'</span><span id="live-status" class="lh-status '+(s.open?'open':'closed')+'">'+esc(s.open?t("live_open"):t("live_closed_b"))+'</span></div>'
      +'<div class="lh-ctrls">'
        +'<button class="btn btn-ghost sm" data-action="'+(s.open?'liveclosea':'liveopena')+'">'+(s.open?'⏸ '+esc(t("live_pause")):'▶ '+esc(t("live_resume")))+'</button>'
        +'<button class="btn btn-ghost sm" data-action="liveclear">🧹 '+esc(t("live_clear"))+'</button>'
        +(s.type==='cloud'?'<button class="btn btn-ghost sm" data-action="livepng">🖼 PNG</button>':'')
        +'<button class="btn btn-ghost sm" data-action="livejson">⬇ JSON</button>'
        +'<button class="btn btn-ghost sm danger" data-action="liveenda">⏹ '+esc(t("live_end"))+'</button>'
      +'</div>'
    +'</div>'
    +'<div class="lh-main">'
      +'<div class="lh-stage">'
        +(s.deck?'<div class="deck-nav"><button class="btn btn-ghost sm" data-action="deckprev"'+(s.deck.current<=0?' disabled':'')+'>◀ '+esc(t("deck_prev"))+'</button><span class="deck-ind">🎬 '+esc(t("deck_slide"))+' '+(s.deck.current+1)+'/'+s.deck.total+'</span><button class="btn btn-ghost sm" data-action="decknext"'+(s.deck.current>=s.deck.total-1?' disabled':'')+'>'+esc(t("deck_next"))+' ▶</button></div>':'')
        +'<h2 class="lh-prompt">'+esc(s.prompt)+'</h2>'
        +'<div id="live-viz" class="live-viz '+s.type+'"></div>'
        +'<div class="lh-foot"><span class="lh-count"><b id="live-count">'+s.count+'</b> '+esc(t("live_responses"))+'</span></div>'
      +'</div>'
      +'<aside class="lh-join"><div class="join-card">'
        +'<div class="jc-label">'+esc(t("live_join_at"))+'</div>'
        +'<div class="jc-host">'+esc(location.host+location.pathname)+'</div>'
        +'<div class="jc-code">'+esc(s.code)+'</div>'
        +'<div id="live-qr" class="jc-qr"></div>'
        +'<div class="jc-actions"><button class="btn btn-ghost sm" data-action="livecopy">⧉ '+esc(t("live_copy"))+'</button>'+(navigator.share?'<button class="btn btn-ghost sm" data-action="liveshare">↗ '+esc(t("live_share"))+'</button>':'')+'</div>'
      +'</div></aside>'
    +'</div>'
  +'</div>';
}
function afterLiveHost(){ const s=state.live; if(!s) return; if(s.type==="game"){ afterGameHost(); return; } if(s.type==="assign"){ afterAssignHost(); return; } const q=document.getElementById("live-qr"); if(q) qrInto(q, joinURLFor(s.code), 6); updateLiveViz(); }

/* ---------- participant ---------- */
function getVoter(){ let v=null; try{ v=localStorage.getItem(STORE_KEY+"_voter"); }catch(e){} if(!v){ v=Math.random().toString(36).slice(2)+Date.now().toString(36); try{ localStorage.setItem(STORE_KEY+"_voter",v); }catch(e){} } return v; }
function loadVoted(code){ try{ return new Set(JSON.parse(localStorage.getItem(STORE_KEY+"_voted_"+code))||[]); }catch(e){ return new Set(); } }
function saveVoted(code,set){ try{ localStorage.setItem(STORE_KEY+"_voted_"+code, JSON.stringify(Array.from(set))); }catch(e){} }
function openJoin(code){
  if(!onlineOnly()){ state.screen="livehub"; render(); return; }
  code=(code||"").toUpperCase().replace(/[^A-Z0-9]/g,"").slice(0,12);
  if(!code){ toast(t("live_need_code")); state.screen="livehub"; render(); return; }
  let pname="",savedPid=null; try{ pname=localStorage.getItem(STORE_KEY+"_pname")||""; savedPid=localStorage.getItem(STORE_KEY+"_pid_"+code)||null; }catch(e){}
  state.liveRole="participant";
  state.join={ code:code, session:null, error:null, name:pname, busy:false, submittedCount:0, voted_poll:false, voted:loadVoted(code), voter:getVoter(), pid:savedPid, avatar:0 };
  state.screen="livejoin"; render();
  fetchJoin(true);
}
function fetchJoin(first){
  const j=state.join; if(!j) return; const code=j.code;
  api("live_get",{query:{code:code, pid:j.pid||undefined}}).then(d=>{
    if(!state.join || state.join.code!==code) return;
    const s=d.session; state.join.session=s; state.join.error=null;
    if(s.type==="game"){
      if(first){
        if(j.pid && s.me){ state._gkey=s.phase+":"+s.qIndex; setRem(s); render(); startGameLoops(gamePlayerPoll,gamePlayerTick); }
        else { state.join.pid=null; render(); }
      } else { render(); }
      return;
    }
    if(s.type==="assign"){ if(first && (!s.me)) state.join.pid=null; render(); return; }
    if(first){ if(s.deck) j.deckSlide=s.deck.current; render(); startPoll(()=>fetchJoin(false)); return; }
    if(s.deck && j.deckSlide!==s.deck.current){ j.deckSlide=s.deck.current; j.voted_poll=false; j.submittedCount=0; j.scaleVals=null; j.alloc=null; j.rankOrder=null; render(); return; }
    updateJoinViz();
  }).catch(e=>{ if(!state.join||state.join.code!==code) return; if(e.status===404){ stopPoll(); state.join.error=t("live_notfound"); } else { state.join.error=apiErr(e); } if(first) render(); });
}
function updateJoinViz(){ const j=state.join; if(!j||!j.session) return; const viz=document.getElementById("join-viz"); if(viz) renderResultsInto(viz, j.session, false, j.voted); }
function sbody(s, extra){ const b=Object.assign({code:s.code}, extra||{}); if(s&&s.deck) b.slide=s.deck.current; return b; }
function joinSubmit(){
  const j=state.join; const s=j&&j.session; if(!s) return;
  if(!s.open){ toast(t("live_closed")); return; }
  const inp=document.getElementById("join-input"); let text=(inp&&inp.value||"").trim();
  if(!text){ toast(t("live_need_text")); return; }
  const nm=document.getElementById("join-name"); if(nm){ j.name=(nm.value||"").trim(); try{ localStorage.setItem(STORE_KEY+"_pname",j.name); }catch(e){} }
  const body=sbody(s,{ text:text, name:j.name||"" });
  const hp=document.getElementById("join-hp"); if(hp&&hp.value) body.website=hp.value;
  j.busy=true; render();
  api("live_submit",{body:body}).then(d=>{ j.session=d.session; j.busy=false; j.submittedCount=(j.submittedCount||0)+1; render(); toast((s.type==="qa"&&s.mod)?t("live_sent_mod"):t("live_sent")); })
    .catch(e=>{ j.busy=false; toast(e.status===423?t("live_closed"):(e.status===429?t("live_slow"):apiErr(e))); render(); });
}
function joinPollVote(opt){
  const j=state.join; const s=j&&j.session; if(!s) return;
  if(!s.open){ toast(t("live_closed")); return; }
  j.busy=true; render();
  api("live_submit",{body:sbody(s,{text:opt})}).then(d=>{ j.session=d.session; j.busy=false; j.voted_poll=true; render(); toast(t("live_sent")); })
    .catch(e=>{ j.busy=false; toast(apiErr(e)); render(); });
}
function joinRate(v){ const j=state.join; const s=j&&j.session; if(!s) return; if(!s.open){ toast(t("live_closed")); return; }
  j.busy=true; render();
  api("live_submit",{body:sbody(s,{value:v})}).then(d=>{ j.session=d.session; j.busy=false; j.voted_poll=true; render(); toast(t("live_sent")); })
    .catch(e=>{ j.busy=false; toast(e.status===423?t("live_closed"):apiErr(e)); render(); }); }
function scaleSet(si,v){ const j=state.join; if(!j||!j.scaleVals||j.busy) return; j.scaleVals[si]=v; render(); }
function scaleSubmit(){ const j=state.join; const s=j&&j.session; if(!s||!j.scaleVals) return; if(!s.open){ toast(t("live_closed")); return; } if(j.scaleVals.some(function(v){return v<1;})){ toast(t("scale_all")); return; }
  j.busy=true; render();
  api("live_submit",{body:sbody(s,{ratings:j.scaleVals})}).then(d=>{ j.session=d.session; j.busy=false; j.voted_poll=true; render(); toast(t("live_sent")); })
    .catch(e=>{ j.busy=false; toast(e.status===423?t("live_closed"):apiErr(e)); render(); }); }
function ptStep(oi,d){ const j=state.join; const s=j&&j.session; if(!j||!j.alloc||!s||j.busy) return; const budget=s.budget||100; const used=j.alloc.reduce(function(a,b){return a+b;},0); const nv=j.alloc[oi]+d; if(nv<0) return; if(d>0 && used>=budget) return; j.alloc[oi]=nv; render(); }
function ptSubmit(){ const j=state.join; const s=j&&j.session; if(!s||!j.alloc) return; if(!s.open){ toast(t("live_closed")); return; } const budget=s.budget||100; const used=j.alloc.reduce(function(a,b){return a+b;},0); if(used!==budget){ toast(t("pt_useall")); return; }
  j.busy=true; render();
  api("live_submit",{body:sbody(s,{alloc:j.alloc})}).then(d=>{ j.session=d.session; j.busy=false; j.voted_poll=true; render(); toast(t("live_sent")); })
    .catch(e=>{ j.busy=false; toast(e.status===423?t("live_closed"):apiErr(e)); render(); }); }
function rankMove(pos,dir){ const j=state.join; if(!j||!j.rankOrder) return; const np=pos+dir; if(np<0||np>=j.rankOrder.length) return; const a=j.rankOrder.slice(); const tmp=a[pos]; a[pos]=a[np]; a[np]=tmp; j.rankOrder=a; render(); }
function rankSubmit(){ const j=state.join; const s=j&&j.session; if(!s||!j.rankOrder) return; if(!s.open){ toast(t("live_closed")); return; }
  j.busy=true; render();
  api("live_submit",{body:sbody(s,{order:j.rankOrder})}).then(d=>{ j.session=d.session; j.busy=false; j.voted_poll=true; render(); toast(t("live_sent")); })
    .catch(e=>{ j.busy=false; toast(e.status===423?t("live_closed"):apiErr(e)); render(); }); }
function joinVote(id){
  const j=state.join; const s=j&&j.session; if(!s||j.voted.has(id)) return;
  j.voted.add(id); saveVoted(s.code,j.voted); updateJoinViz();
  api("live_vote",{body:{code:s.code, id:id, voter:j.voter}}).then(()=>fetchJoin(false)).catch(()=>{});
}
function viewLiveJoin(){
  const j=state.join;
  if(j.session && j.session.type==="game") return viewGameJoin();
  if(j.session && j.session.type==="assign") return viewAssignPlay();
  if(j.error){ return '<div class="join-view"><div class="jv-head"><a class="backlink" data-action="joinleave">←</a></div><div class="join-err">⚠ '+esc(j.error)+'<div style="margin-top:16px"><button class="btn btn-primary" data-action="joinleave">'+esc(t("back_home"))+'</button></div></div></div>'; }
  if(!j.session){ return '<div class="join-view"><div class="join-loading">'+esc(t("live_loading"))+'</div></div>'; }
  const s=j.session; const closed=!s.open;
  let input="";
  if(s.type==="poll"){
    input = j.voted_poll ? '<div class="join-done">✓ '+esc(t("live_voted"))+'</div>'
      : '<div class="poll-choices">'+(s.options||[]).map(o=>'<button class="poll-choice" data-action="joinpoll" data-opt="'+esc(o)+'"'+(j.busy?' disabled':'')+'>'+esc(o)+'</button>').join("")+'</div>';
  } else if(s.type==="qa"){
    input = '<input id="join-name" class="input" maxlength="40" placeholder="'+esc(t("fb_name"))+'" value="'+esc(j.name||"")+'">'
      +'<textarea id="join-input" class="textarea" rows="2" maxlength="240" placeholder="'+esc(t("live_qa_ph"))+'"></textarea>'
      +'<button class="btn btn-primary btn-block" data-action="joinsubmit"'+((j.busy||closed)?' disabled':'')+'>'+(j.busy?"…":"✓ "+esc(t("live_send")))+'</button>';
  } else if(s.type==="rating"){
    const scale=s.scale||5;
    input = j.voted_poll ? '<div class="join-done">✓ '+esc(t("live_voted"))+'</div>'
      : (scale===5
        ? '<div class="rate-stars">'+[1,2,3,4,5].map(v=>'<button class="rate-star" data-action="joinrate" data-v="'+v+'"'+((j.busy||closed)?' disabled':'')+'>★</button>').join("")+'</div>'
        : '<div class="rate-nps">'+Array.from({length:11},(x,v)=>'<button class="rate-np" data-action="joinrate" data-v="'+v+'"'+((j.busy||closed)?' disabled':'')+'>'+v+'</button>').join("")+'</div><div class="rate-nps-lbl"><span>'+esc(t("nps_low"))+'</span><span>'+esc(t("nps_high"))+'</span></div>');
  } else if(s.type==="rank"){
    if(!j.rankOrder || j.rankOrder.length!==(s.options||[]).length){ j.rankOrder=(s.options||[]).map((x,i)=>i); }
    input = j.voted_poll ? '<div class="join-done">✓ '+esc(t("live_voted"))+'</div>'
      : '<div class="rank-list">'+j.rankOrder.map((idx,pos)=>'<div class="rank-item"><span class="rank-pos">'+(pos+1)+'</span><span class="rank-txt">'+esc((s.options||[])[idx])+'</span><span class="rank-ctrls"><button class="rank-mv" data-action="rankup" data-pos="'+pos+'"'+(pos===0?' disabled':'')+'>▲</button><button class="rank-mv" data-action="rankdown" data-pos="'+pos+'"'+(pos===j.rankOrder.length-1?' disabled':'')+'>▼</button></span></div>').join("")+'</div>'
        +'<button class="btn btn-primary btn-block" data-action="ranksubmit"'+((j.busy||closed)?' disabled':'')+'>'+(j.busy?"…":"✓ "+esc(t("rank_submit")))+'</button>';
  } else if(s.type==="scale"){
    const stmts=s.statements||[]; if(!j.scaleVals || j.scaleVals.length!==stmts.length){ j.scaleVals=stmts.map(function(){return 0;}); }
    const allRated=j.scaleVals.every(function(v){return v>=1;});
    input = j.voted_poll ? '<div class="join-done">✓ '+esc(t("live_voted"))+'</div>'
      : '<div class="sc-input">'+stmts.map(function(st,si){ return '<div class="scq"><div class="scq-label">'+esc(st)+'</div><div class="scq-dots">'+[1,2,3,4,5].map(function(v){ return '<button class="scq-dot'+((j.scaleVals[si]===v)?' on':'')+'" data-action="scaleset" data-si="'+si+'" data-v="'+v+'"'+((j.busy||closed)?' disabled':'')+'>'+v+'</button>'; }).join("")+'</div></div>'; }).join("")+'</div>'
        +'<div class="scq-ends"><span>1 · '+esc(t("scale_lo"))+'</span><span>'+esc(t("scale_hi"))+' · 5</span></div>'
        +'<button class="btn btn-primary btn-block" style="margin-top:12px" data-action="scalesubmit"'+((j.busy||closed||!allRated)?' disabled':'')+'>'+(j.busy?"…":"✓ "+esc(t("live_send")))+'</button>';
  } else if(s.type==="points"){
    const opts=s.options||[]; const budget=s.budget||100; if(!j.alloc || j.alloc.length!==opts.length){ j.alloc=opts.map(function(){return 0;}); }
    const used=j.alloc.reduce(function(a,b){return a+b;},0); const rem=budget-used;
    input = j.voted_poll ? '<div class="join-done">✓ '+esc(t("live_voted"))+'</div>'
      : '<div class="pt-budget">'+esc(t("pt_remaining"))+' <b class="'+(rem===0?"pt-ok":"")+'">'+rem+'</b> / '+budget+'</div>'
        +'<div class="pt-input">'+opts.map(function(o,oi){ return '<div class="ptq"><span class="ptq-label">'+esc(o)+'</span><span class="ptq-ctrl"><button class="pt-step" data-action="ptminus" data-oi="'+oi+'"'+((j.busy||closed||j.alloc[oi]<=0)?" disabled":"")+'>−</button><b class="ptq-val">'+j.alloc[oi]+'</b><button class="pt-step" data-action="ptplus" data-oi="'+oi+'"'+((j.busy||closed||rem<=0)?" disabled":"")+'>+</button></span></div>'; }).join("")+'</div>'
        +'<button class="btn btn-primary btn-block" style="margin-top:12px" data-action="ptsubmit"'+((j.busy||closed||used!==budget)?' disabled':'')+'>'+(j.busy?"…":(used!==budget?esc(t("pt_useall")):"✓ "+esc(t("live_send"))))+'</button>';
  } else {
    const left=(s.multi||1)-(j.submittedCount||0);
    input = (left>0 && !closed)
      ? '<input id="join-input" class="input big-input" maxlength="40" placeholder="'+esc(t("live_word_ph"))+'">'
        +'<button class="btn btn-primary btn-block" data-action="joinsubmit"'+(j.busy?' disabled':'')+'>'+(j.busy?"…":"✓ "+esc(t("live_send")))+'</button>'
        +'<div class="join-hint">'+esc(t("live_words_left",left))+'</div>'
      : '<div class="join-done">✓ '+esc(t("live_thanks_words"))+'</div>';
  }
  return '<div class="join-view">'
    +'<div class="jv-head"><a class="backlink" data-action="joinleave">← '+esc(t("live_leave"))+'</a><span class="jv-code">'+esc(s.code)+'</span></div>'
    +(s.deck?'<div class="jv-deck">🎬 '+esc(t("deck_slide"))+' '+(s.deck.current+1)+'/'+s.deck.total+'</div>':'')
    +'<h2 class="jv-prompt">'+esc(s.prompt)+'</h2>'
    +(closed?'<div class="jv-closed">⏸ '+esc(t("live_closed_note"))+'</div>':'')
    +'<div class="jv-input">'+input+'<input id="join-hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0"></div>'
    +'<div class="jv-results"><div class="jv-rlabel">'+esc(t("live_live_results"))+'</div><div id="join-viz" class="live-viz '+s.type+' join"></div></div>'
  +'</div>';
}
function afterLiveJoin(){ const j=state.join; if(j&&j.session&&j.session.type==="game"){ afterGameJoin(); return; } if(j&&j.session&&j.session.type==="assign"){ afterAssignJoin(); return; } if(j&&j.session){ updateJoinViz(); const inp=document.getElementById("join-input"); if(inp){ inp.addEventListener("keydown",ev=>{ if(ev.key==="Enter"){ if(inp.tagName==="TEXTAREA"&&ev.shiftKey) return; ev.preventDefault(); joinSubmit(); } }); if(inp.tagName!=="TEXTAREA") inp.focus(); } } }

/* ---------- live hub (choose: join, or host new) ---------- */
function syncBuilder(){ const lb=state.liveBuilder; const p=document.getElementById("lb-prompt"); if(p) lb.prompt=p.value;
  const m=document.getElementById("lb-multi"); if(m) lb.multi=Math.max(1,Math.min(10,parseInt(m.value,10)||1));
  if(lb.type==="poll"||lb.type==="rank"||lb.type==="points"){ const opts=[]; document.querySelectorAll("[id^=lb-opt-]").forEach(el=>opts.push(el.value)); if(opts.length) lb.options=opts; }
  if(lb.type==="scale"){ const sts=[]; document.querySelectorAll("[id^=lb-stmt-]").forEach(el=>sts.push(el.value)); if(sts.length) lb.statements=sts; }
  const dt=document.getElementById("deck-title"); if(dt&&state.deck) state.deck.title=dt.value; }
function viewLiveHub(){
  const lb=state.liveBuilder; const admin=(!state.server)||state.admin;
  const types=[["cloud","☁️",t("live_t_cloud"),t("live_t_cloud_d")],["poll","📊",t("live_t_poll"),t("live_t_poll_d")],["rating","⭐",t("live_t_rating"),t("live_t_rating_d")],["rank","🔢",t("live_t_rank"),t("live_t_rank_d")],["scale","📈",t("live_t_scale"),t("live_t_scale_d")],["points","🎯",t("live_t_points"),t("live_t_points_d")],["qa","💬",t("live_t_qa"),t("live_t_qa_d")]];
  const cards=types.map(a=>'<button class="ltype'+(lb.type===a[0]?' on':'')+'" data-action="livetype" data-v="'+a[0]+'"><span class="lt-ic">'+a[1]+'</span><span class="lt-nm">'+esc(a[2])+'</span><span class="lt-d">'+esc(a[3])+'</span></button>').join("");
  let extra="";
  if(lb.type==="poll"){ const opts=(lb.options&&lb.options.length?lb.options:["",""]);
    extra='<div class="field"><label>'+esc(t("live_options"))+'</label>'+opts.map((o,i)=>'<div class="opt-row"><input id="lb-opt-'+i+'" class="input" maxlength="80" placeholder="'+esc(t("live_option"))+' '+(i+1)+'" value="'+esc(o)+'">'+(opts.length>2?'<button class="iconbtn sm" data-action="livermopt" data-i="'+i+'" title="✕">✕</button>':'')+'</div>').join("")+(opts.length<10?'<button class="btn btn-ghost sm" data-action="liveaddopt">+ '+esc(t("live_add_option"))+'</button>':'')+'</div>';
  } else if(lb.type==="rank"){ const opts=(lb.options&&lb.options.length?lb.options:["",""]);
    extra='<div class="field"><label>'+esc(t("live_rank_opts"))+'</label>'+opts.map((o,i)=>'<div class="opt-row"><input id="lb-opt-'+i+'" class="input" maxlength="80" placeholder="'+esc(t("live_option"))+' '+(i+1)+'" value="'+esc(o)+'">'+(opts.length>2?'<button class="iconbtn sm" data-action="livermopt" data-i="'+i+'" title="✕">✕</button>':'')+'</div>').join("")+(opts.length<8?'<button class="btn btn-ghost sm" data-action="liveaddopt">+ '+esc(t("live_add_option"))+'</button>':'')+'</div>';
  } else if(lb.type==="rating"){
    extra='<div class="field"><label>'+esc(t("live_rating_scale"))+'</label><div class="seg">'
      +'<button class="seg-btn'+(((lb.scale||5)==5)?' on':'')+'" data-action="liveratscale" data-v="5">⭐ 1–5</button>'
      +'<button class="seg-btn'+(((lb.scale||5)==10)?' on':'')+'" data-action="liveratscale" data-v="10">NPS 0–10</button></div></div>';
  } else if(lb.type==="scale"){ const sts=(lb.statements&&lb.statements.length?lb.statements:["",""]);
    extra='<div class="field"><label>'+esc(t("live_statements"))+'</label>'+sts.map((o,i)=>'<div class="opt-row"><input id="lb-stmt-'+i+'" class="input" maxlength="80" placeholder="'+esc(t("live_statement"))+' '+(i+1)+'" value="'+esc(o)+'">'+(sts.length>2?'<button class="iconbtn sm" data-action="livermstmt" data-i="'+i+'" title="✕">✕</button>':'')+'</div>').join("")+(sts.length<6?'<button class="btn btn-ghost sm" data-action="liveaddstmt">+ '+esc(t("live_add_stmt"))+'</button>':'')+'<div class="join-hint">'+esc(t("live_scale_hint"))+'</div></div>';
  } else if(lb.type==="points"){ const opts=(lb.options&&lb.options.length?lb.options:["",""]);
    extra='<div class="field"><label>'+esc(t("live_points_opts"))+'</label>'+opts.map((o,i)=>'<div class="opt-row"><input id="lb-opt-'+i+'" class="input" maxlength="80" placeholder="'+esc(t("live_option"))+' '+(i+1)+'" value="'+esc(o)+'">'+(opts.length>2?'<button class="iconbtn sm" data-action="livermopt" data-i="'+i+'" title="✕">✕</button>':'')+'</div>').join("")+(opts.length<8?'<button class="btn btn-ghost sm" data-action="liveaddopt">+ '+esc(t("live_add_option"))+'</button>':'')+'<div class="join-hint">'+esc(t("live_points_hint"))+'</div></div>';
  } else if(lb.type==="qa"){
    extra='<div class="field"><label>'+esc(t("live_qa_mod"))+'</label><div class="seg"><button class="seg-btn'+(!lb.mod?" on":"")+'" data-action="livemodset" data-v="0">'+esc(t("live_qa_mod_off"))+'</button><button class="seg-btn'+(lb.mod?" on":"")+'" data-action="livemodset" data-v="1">'+esc(t("live_qa_mod_on"))+'</button></div><div class="join-hint">'+esc(t("live_qa_mod_hint"))+'</div></div>'+'<div class="field"><label>'+esc(t("live_filter"))+'</label><div class="seg"><button class="seg-btn'+(!lb.filter?" on":"")+'" data-action="livefilterset" data-v="0">'+esc(t("live_filter_off"))+'</button><button class="seg-btn'+(lb.filter?" on":"")+'" data-action="livefilterset" data-v="1">'+esc(t("live_filter_on"))+'</button></div><div class="join-hint">'+esc(t("live_filter_hint"))+'</div></div>';
  } else if(lb.type==="cloud"){
    extra='<div class="field"><label>'+esc(t("live_max_words"))+'</label><input id="lb-multi" class="input" type="number" min="1" max="10" value="'+(lb.multi||1)+'" style="max-width:130px"></div>'+'<div class="field"><label>'+esc(t("live_filter"))+'</label><div class="seg"><button class="seg-btn'+(!lb.filter?" on":"")+'" data-action="livefilterset" data-v="0">'+esc(t("live_filter_off"))+'</button><button class="seg-btn'+(lb.filter?" on":"")+'" data-action="livefilterset" data-v="1">'+esc(t("live_filter_on"))+'</button></div><div class="join-hint">'+esc(t("live_filter_hint"))+'</div></div>';
  }
  const deckMode=(state.liveMode==="deck");
  const modeToggle = admin ? '<div class="live-mode-seg"><div class="seg"><button class="seg-btn'+(deckMode?"":" on")+'" data-action="livemode" data-v="single">'+esc(t("live_mode_single"))+'</button><button class="seg-btn'+(deckMode?" on":"")+'" data-action="livemode" data-v="deck">🎬 '+esc(t("live_mode_deck"))+'</button></div></div>' : '';
  let hostCard;
  if(!admin){ hostCard='<div class="panel"><div class="about">'+esc(t("live_host_login"))+'</div><button class="btn btn-primary btn-block" style="margin-top:14px" data-action="openlogin">'+esc(t("login"))+'</button></div>'; }
  else if(deckMode){ const dsl=state.deck.slides;
    const list = dsl.length ? '<div class="deck-slides">'+dsl.map((sl,i)=>'<div class="deck-srow"><span class="ds-ic">'+deckIcon(sl.type)+'</span><span class="ds-num">'+(i+1)+'</span><span class="ds-txt">'+esc(sl.prompt)+'</span><span class="ds-ctrls"><button class="rank-mv" data-action="deckup" data-i="'+i+'"'+(i===0?' disabled':'')+'>▲</button><button class="rank-mv" data-action="deckdown" data-i="'+i+'"'+(i===dsl.length-1?' disabled':'')+'>▼</button><button class="iconbtn sm" data-action="deckrm" data-i="'+i+'" title="✕">✕</button></span></div>').join("")+'</div>' : '<div class="deck-empty">'+esc(t("deck_empty"))+'</div>';
    hostCard='<div class="panel">'
      +'<div class="field"><label>'+esc(t("deck_title"))+'</label><input id="deck-title" class="input" maxlength="200" placeholder="'+esc(t("deck_title_ph"))+'" value="'+esc(state.deck.title||"")+'"></div>'
      +'<div class="deck-added-h">🎬 '+esc(t("deck_slides"))+' ('+dsl.length+')</div>'+list
      +'<div class="deck-compose"><div class="deck-compose-h">'+esc(t("deck_add_new"))+'</div>'
        +'<div class="field"><label>'+esc(t("live_prompt"))+'</label><input id="lb-prompt" class="input" maxlength="200" placeholder="'+esc(t("live_prompt_ph"))+'" value="'+esc(lb.prompt||"")+'"></div>'+extra
        +'<button class="btn btn-ghost btn-block" data-action="deckadd">➕ '+esc(t("deck_add_slide"))+'</button></div>'
      +'<div class="deck-io"><button class="btn btn-ghost sm" data-action="deckexport"'+(dsl.length<1?' disabled':'')+'>⬇ '+esc(t("deck_export"))+'</button><button class="btn btn-ghost sm" data-action="deckimport">⬆ '+esc(t("deck_import"))+'</button><input type="file" id="deck-file" accept="application/json,.json" style="display:none"></div>'
      +'<button class="btn btn-primary btn-block" style="margin-top:14px" data-action="decklaunch"'+(dsl.length<1?' disabled':'')+'>🚀 '+esc(t("deck_launch"))+'</button>'
    +'</div>';
  } else { hostCard='<div class="panel"><div class="field"><label>'+esc(t("live_prompt"))+'</label><input id="lb-prompt" class="input" maxlength="200" placeholder="'+esc(t("live_prompt_ph"))+'" value="'+esc(lb.prompt||"")+'"></div>'+extra+'<button class="btn btn-primary btn-block" data-action="livestart">🚀 '+esc(t("live_launch"))+'</button></div>'; }
  return '<div class="wrap">'
    +'<a class="backlink" data-action="home">← '+esc(t("back_home"))+'</a>'
    +'<div class="page-head"><div><h2>📡 '+esc(t("live_title"))+'</h2><div class="sub">'+esc(t("live_sub"))+'</div></div></div>'
    +'<div class="panel"><div class="field" style="margin-bottom:0"><label>'+esc(t("live_join_code"))+'</label><div class="opt-row"><input id="lb-join" class="input big-input" maxlength="12" placeholder="'+esc(t("live_code_ph"))+'" style="text-transform:uppercase;letter-spacing:4px;font-weight:800"><button class="btn btn-primary" data-action="joincode" style="white-space:nowrap">'+esc(t("live_join"))+'</button></div></div></div>'
    +'<div class="ltype-head">'+esc(t("live_host_new"))+'</div>'
    +modeToggle
    +'<div class="ltype-grid">'+cards+'</div>'
    +hostCard
  +'</div>';
}
function afterLiveHub(){ const el=document.getElementById("lb-join"); if(el){ el.addEventListener("keydown",ev=>{ if(ev.key==="Enter"){ ev.preventDefault(); const v=(el.value||"").trim(); if(v) openJoin(v); else toast(t("live_need_code")); } }); }
  const df=document.getElementById("deck-file"); if(df && !df._b){ df._b=true; df.addEventListener("change",ev=>{ const file=ev.target.files&&ev.target.files[0]; if(file) deckImportFile(file); ev.target.value=""; }); } }
function deckIcon(tp){ return ({cloud:"☁️",poll:"📊",qa:"💬",rating:"⭐",rank:"🔢",scale:"📈",points:"🎯"})[tp]||"•"; }
function deckAdd(){ syncBuilder(); const lb=state.liveBuilder;
  if(!lb.prompt.trim()){ toast(t("live_need_prompt")); return; }
  const sl={ type:lb.type, prompt:lb.prompt.trim(), scale:lb.scale||5, budget:lb.budget||100, multi:lb.multi||1, mod:!!lb.mod, filter:!!lb.filter, options:[], statements:[] };
  if(lb.type==="poll"||lb.type==="rank"||lb.type==="points"){ sl.options=(lb.options||[]).map(o=>o.trim()).filter(Boolean); if(sl.options.length<2){ toast(t("live_need_opts")); return; } }
  if(lb.type==="scale"){ sl.statements=(lb.statements||[]).map(o=>o.trim()).filter(Boolean); if(sl.statements.length<2){ toast(t("live_need_stmts")); return; } }
  if(state.deck.slides.length>=50){ toast(t("deck_full")); return; }
  state.deck.slides.push(sl);
  state.liveBuilder.prompt=""; state.liveBuilder.options=["",""]; state.liveBuilder.statements=["",""];
  render(); toast(t("deck_added")); }
function createDeck(){ if(!onlineOnly()) return; if(!requireAdmin()) return;
  const slides=state.deck.slides; if(!slides.length){ toast(t("deck_need_slides")); return; }
  api("live_create",{body:{ type:"deck", prompt:(state.deck.title||"").trim()||t("deck_default_title"), slides:slides }})
    .then(d=>{ state.live=d.session; state.liveRole="host"; state.screen="livehost"; render(); startPoll(refreshLive); })
    .catch(e=>toast(apiErr(e))); }
function deckExport(){ syncBuilder(); const d=state.deck; if(!d.slides.length){ toast(t("deck_need_slides")); return; }
  const def={ v:1, kind:"qff-deck", title:d.title||"", slides:d.slides.map(sl=>({type:sl.type,prompt:sl.prompt,options:sl.options||[],statements:sl.statements||[],scale:sl.scale||5,budget:sl.budget||100,multi:sl.multi||1,mod:!!sl.mod,filter:!!sl.filter})) };
  const nm=(d.title||"prezentare").replace(/[^a-z0-9]+/gi,"-").replace(/^-+|-+$/g,"").slice(0,40)||"prezentare";
  liveDL(new Blob([JSON.stringify(def,null,2)],{type:"application/json"}), nm+".json"); }
function deckImportFile(file){ const TYPES=["cloud","poll","qa","rating","rank","scale","points"]; const r=new FileReader();
  r.onload=()=>{ let o; try{ o=JSON.parse(r.result); }catch(e){ toast(t("deck_import_bad")); return; }
    const raw=(o&&Array.isArray(o.slides))?o.slides:null; if(!raw){ toast(t("deck_import_bad")); return; }
    const valid=[];
    raw.forEach(sl=>{ if(!sl||TYPES.indexOf(sl.type)<0) return; const p=String(sl.prompt||"").trim(); if(!p) return;
      const s2={ type:sl.type, prompt:p, options:Array.isArray(sl.options)?sl.options.map(x=>String(x)):[], statements:Array.isArray(sl.statements)?sl.statements.map(x=>String(x)):[], scale:(sl.scale===10?10:5), budget:(typeof sl.budget==="number"&&sl.budget>=10&&sl.budget<=1000)?sl.budget:100, multi:(typeof sl.multi==="number"&&sl.multi>=1&&sl.multi<=10)?Math.round(sl.multi):1, mod:!!sl.mod, filter:!!sl.filter };
      if(sl.type==="poll"||sl.type==="rank"||sl.type==="points"){ s2.options=s2.options.map(x=>x.trim()).filter(Boolean); if(s2.options.length<2) return; }
      if(sl.type==="scale"){ s2.statements=s2.statements.map(x=>x.trim()).filter(Boolean); if(s2.statements.length<2) return; }
      valid.push(s2); });
    if(!valid.length){ toast(t("deck_import_empty")); return; }
    state.deck.slides=valid.slice(0,50); if(o.title) state.deck.title=String(o.title); state.liveMode="deck"; render(); toast(t("deck_imported",valid.length)); };
  r.readAsText(file); }


/* ============================================================================
   LIVE MULTIPLAYER QUIZ GAME (Kahoot-style) — session type "game"
   ========================================================================== */
const AVATARS=["🦊","🐼","🦉","🐯","🐸","🦄","🐙","🦋","🐝","🐬","🦅","🐺","🦁","🐲","🌟","⚡","🚀","🛸","👾","🎯","🔥","💎","🍀","🌈"];
function avEmoji(i){ return AVATARS[(i|0)%AVATARS.length]; }
const REACT_EMOJIS=["👏","❤️","😮","😂","🔥","🎉"];
const TEAM_PRESETS=[{name:"Roșii",emoji:"🔴",color:"#ff4e5b"},{name:"Albaștrii",emoji:"🔵",color:"#3b82f6"},{name:"Verzii",emoji:"🟢",color:"#22c55e"},{name:"Galbenii",emoji:"🟡",color:"#eab308"}];
function reactBar(){ return '<div class="react-bar">'+REACT_EMOJIS.map((e,i)=>'<button class="react-btn" data-action="greact" data-e="'+i+'">'+e+'</button>').join("")+'</div>'; }
function sendReaction(e){ const j=state.join; const s=j&&j.session; if(!s||!s.code) return; if(state._reactLock) return; state._reactLock=true; setTimeout(()=>{ state._reactLock=false; }, 350); api("live_react",{body:{code:s.code,e:e}}).catch(()=>{}); const btn=document.querySelector('.react-btn[data-e="'+e+'"]'); if(btn){ btn.classList.add("pop"); setTimeout(()=>btn.classList.remove("pop"),300); } }
function spawnReactions(reactions){ if(!reactions||!reactions.length) return; let ov=document.getElementById("react-overlay"); if(!ov){ ov=document.createElement("div"); ov.id="react-overlay"; ov.className="react-overlay"; document.body.appendChild(ov); }
  const last=state._lastReactTs||0; let mx=last;
  reactions.forEach(r=>{ if(r.ts>last){ mx=Math.max(mx,r.ts); const el=document.createElement("div"); el.className="react-float"; el.textContent=REACT_EMOJIS[r.e]||"❤️"; el.style.left=(6+Math.random()*86)+"%"; el.style.setProperty("--dx",(Math.random()*70-35)+"px"); el.style.setProperty("--dur",(2.4+Math.random()*1.3)+"s"); ov.appendChild(el); setTimeout(()=>el.remove(),3900); } });
  state._lastReactTs=mx; }
function gameRemaining(){ return Math.max(0,(state._remBase||0)-(Date.now()-(state._remAt||Date.now()))); }
function setRem(s){ if(s&&s.question&&typeof s.question.remaining==="number"){ state._remBase=s.question.remaining; state._remAt=Date.now(); } }
function shuffleArr(a){ const r=a.slice(); for(let i=r.length-1;i>0;i--){ const j=Math.floor(Math.random()*(i+1)); const t=r[i]; r[i]=r[j]; r[j]=t; } return r; }
function maybeShuffleQuiz(quiz){ if(!(state.setup&&state.setup.shuffle)) return quiz; const q=JSON.parse(JSON.stringify(quiz)); q.questions=shuffleArr(q.questions||[]); q.questions.forEach(qq=>{ if(qq.type==="quiz"&&Array.isArray(qq.answers)) qq.answers=shuffleArr(qq.answers); }); return q; }
function resolveQuizForGame(quiz){
  return { title:L(quiz.title), desc:L(quiz.desc||""), color:quiz.color||AV_COLORS[1],
    questions:(quiz.questions||[]).map(q=>({ text:L(q.text), time:q.time||20, points:q.points||1000, type:(q.type==="tf"||q.type==="type"||q.type==="num")?q.type:"quiz",
      answers:(q.answers||[]).map(a=>({ text:L(a.text), correct:!!a.correct })), tol:q.tol||0 })) };
}
function createGame(){
  if(!onlineOnly()) return;
  if(!requireAdmin()) return;
  const quiz=maybeShuffleQuiz(resolveQuizForGame(state.setup.quiz));
  api("live_create",{body:{type:"game", quiz:quiz, teams:(state.setup.teamCount>0?TEAM_PRESETS.slice(0,state.setup.teamCount):[])}}).then(d=>{ state.live=d.session; state.liveRole="host"; state.screen="livehost"; render(); })
    .catch(e=>toast(apiErr(e)));
}

/* ---------- host ---------- */
function startGameLoops(pollFn,tickFn){ stopPoll(); state._poll=setInterval(pollFn,1200); state._tick=setInterval(tickFn,200); }
function afterGameHost(){ const s=state.live; if(!s) return; state._gkey=s.phase+":"+s.qIndex; state._autoRev=false; state._lastReactTs=(s.now||Date.now()); setRem(s);
  if(s.phase==="lobby"){ const q=document.getElementById("g-qr"); if(q) qrInto(q, joinURLFor(s.code), 6); }
  startGameLoops(gameHostPoll, gameHostTick); }
function gameHostPoll(){ const s=state.live; if(!s) return; api("live_get",{query:{code:s.code}}).then(d=>{ if(!state.live) return; state.live=d.session; spawnReactions(d.session.reactions);
  const key=d.session.phase+":"+d.session.qIndex; if(key!==state._gkey){ state._gkey=key; state._autoRev=false; setRem(d.session); render(); } else { setRem(d.session); updateGameHostDynamic(); } }).catch(()=>{}); }
function gameHostTick(){ const s=state.live; if(!s||s.phase!=="question"||!s.question) return; const rem=gameRemaining();
  const n=document.getElementById("g-ring-num"); if(n) n.textContent=Math.ceil(rem/1000);
  const r=document.getElementById("g-ring"); if(r) r.style.setProperty("--deg",(360*rem/((s.question.time||20)*1000))+"deg");
  if(rem<=0 && !state._autoRev){ state._autoRev=true; gameControl("reveal"); } }
function updateGameHostDynamic(){ const s=state.live; if(!s) return;
  if(s.phase==="lobby"){ const g=document.getElementById("g-lobby-players"); if(g) g.innerHTML=lobbyPlayersHTML(s); const c=document.getElementById("g-count"); if(c) c.textContent=s.count; const b=document.getElementById("g-start"); if(b) b.disabled=(s.count<1); }
  else if(s.phase==="question"){ const a=document.getElementById("g-answered"); if(a&&s.question) a.textContent=s.question.answeredCount; } }
function gameControl(action,id){ const s=state.live; if(!s) return; api("live_control",{body:{code:s.code,action:action,id:id}}).then(d=>{ if(d.deleted){ stopPoll(); state.live=null; state.screen="livehub"; render(); return; } state.live=d.session; state._gkey=d.session.phase+":"+d.session.qIndex; state._autoRev=false; setRem(d.session); render(); }).catch(e=>toast(apiErr(e))); }

function viewGameHost(){ const s=state.live; if(!s) return "";
  if(s.phase==="lobby") return gameHostLobby(s);
  if(s.phase==="question") return gameHostQuestion(s);
  if(s.phase==="reveal") return gameHostReveal(s);
  if(s.phase==="final") return gameHostFinal(s);
  return ""; }
function lobbyPlayersHTML(s){ const ps=s.players||[]; if(!ps.length) return '<div class="g-lobby-empty">'+esc(t("game_waiting_players"))+'</div>';
  return ps.map(p=>'<div class="g-chip"><span class="g-chip-av">'+avEmoji(p.avatar)+'</span>'+esc(p.name)+'<button class="g-kick" data-action="gkick" data-id="'+esc(p.id)+'" title="✕">✕</button></div>').join(""); }
function gameHostLobby(s){
  return '<div class="game-host">'
    +'<div class="lh-bar"><a class="backlink" data-action="livestop">← '+esc(t("live_back"))+'</a><div class="lh-meta"><span class="lh-type">🎮 '+esc(s.prompt)+'</span></div></div>'
    +'<div class="gl-main">'
      +'<div class="join-card gl-join"><div class="jc-label">'+esc(t("live_join_at"))+'</div><div class="jc-host">'+esc(location.host+location.pathname)+'</div><div class="jc-code">'+esc(s.code)+'</div><div id="g-qr" class="jc-qr"></div></div>'
      +'<div class="gl-players"><div class="gl-ptitle"><b id="g-count">'+s.count+'</b> '+esc(t("game_players"))+'</div>'+(s.teams?'<div class="gl-teams">'+s.teams.map(function(tt){return '<span class="gl-team" style="--tc:'+tt.color+'">'+tt.emoji+' '+esc(tt.name)+' · '+tt.members+'</span>';}).join("")+'</div>':'')+'<div id="g-lobby-players" class="g-chips">'+lobbyPlayersHTML(s)+'</div></div>'
    +'</div>'
    +'<div class="gl-foot"><button id="g-start" class="btn btn-primary btn-lg" data-action="gstart"'+(s.count<1?' disabled':'')+'>▶ '+esc(t("game_start"))+'</button></div>'
  +'</div>';
}
function gameHostQuestion(s){ const q=s.question;
  const body = q.isText
    ? '<div class="gh-typing"><div class="gh-typing-ic">✍️</div><div class="gh-typing-msg">'+esc(t("gh_typing"))+'</div></div>'
    : '<div class="answers showonly'+(q.type==="tf"?" two":"")+'">'+(q.answers||[]).map((a,i)=>'<div class="ans '+ANSCLASS[i]+'"><span class="ico">'+SHAPES[i]+'</span><span>'+esc(a.text)+'</span></div>').join("")+'</div>';
  return '<div class="game-host">'
    +'<div class="lh-bar"><div class="pill">📋 '+(q.index+1)+'/'+s.total+'</div><div class="pill">✓ <b id="g-answered">'+q.answeredCount+'</b>/'+s.count+'</div><div class="lh-ctrls"><button class="btn btn-ghost sm" data-action="greveal">⏭ '+esc(t("game_reveal"))+'</button></div></div>'
    +'<div class="gq-stage"><h2 class="gq-text">'+esc(q.text)+'</h2>'
      +'<div class="gq-ring"><div class="ring big" id="g-ring" style="--deg:360deg"><span id="g-ring-num">'+Math.ceil((q.remaining||0)/1000)+'</span></div></div>'
      +body+'</div>'
  +'</div>';
}
function gameHostReveal(s){ const q=s.question;
  let body;
  if(q.isText){
    const subs=q.submissions||[]; const maxc=Math.max.apply(null,[1].concat(subs.map(x=>x.count||0)));
    const chips=subs.map(x=>'<div class="gh-sub'+(x.correct?" ok":"")+'"><span class="ghs-txt">'+esc(x.text)+'</span><span class="ghs-bar"><i style="width:'+Math.round((x.count||0)/maxc*100)+'%"></i></span><b>'+(x.count||0)+'</b></div>').join("");
    body='<div class="gh-canon">✓ '+esc(t("correct_was"))+' <b>'+esc(q.canonical||"")+'</b> · '+(q.correctCount||0)+'/'+(q.answerCount||0)+' '+esc(t("gh_gotit"))+'</div><div class="gh-subs">'+(chips||'<div class="g-lobby-empty">—</div>')+'</div>';
  } else {
    const counts=(q.answers||[]).map(a=>a.count||0); const maxc=Math.max.apply(null,[1].concat(counts));
    body='<div class="answers showonly'+(q.type==="tf"?" two":"")+'">'+(q.answers||[]).map((a,i)=>'<div class="ans '+ANSCLASS[i]+(a.correct?" correct":" dim")+'"><span class="ico">'+SHAPES[i]+'</span><span>'+esc(a.text)+'</span><span class="g-bar"><i style="width:'+Math.round((a.count||0)/maxc*100)+'%"></i><b>'+(a.count||0)+'</b></span>'+(a.correct?'<span class="g-check">✓</span>':'')+'</div>').join("")+'</div>';
  }
  const lb=(s.leaderboard||[]).slice(0,8).map((p,i)=>'<div class="sb-row"><div class="rk">'+(i+1)+'</div><div class="av" style="background:'+avColor(i)+'">'+avEmoji(p.avatar)+'</div><div class="nm">'+esc(p.name)+'</div><div class="sc">'+p.score+'</div></div>').join("");
  const last=(q.index+1>=s.total);
  return '<div class="game-host">'
    +'<div class="lh-bar"><div class="pill">📋 '+(q.index+1)+'/'+s.total+'</div><div class="lh-ctrls"><button class="btn btn-primary sm" data-action="gnext">'+(last?'🏆 '+esc(t("game_results")):esc(t("game_next"))+' ▶')+'</button></div></div>'
    +'<div class="gr-main"><div class="gr-q"><h2 class="gq-text">'+esc(q.text)+'</h2>'+body+'</div>'
      +'<div class="gr-lb"><div class="gl-ptitle">'+esc(t("scoreboard"))+'</div>'+(lb||'<div class="g-lobby-empty">—</div>')+'</div></div>'
  +'</div>';
}
function gameHostFinal(s){ const lb=s.leaderboard||[]; const top=lb.slice(0,3);
  const pod=top.map((p,i)=>{ const place=i+1; return '<div class="pod p'+place+'" style="animation-delay:'+(0.2*(3-place))+'s"><div class="av" style="background:'+avColor(i)+'">'+avEmoji(p.avatar)+'</div><div class="nm">'+esc(p.name)+'</div><div class="sc">'+p.score+'</div><div class="bar">'+(place===1?'🥇':place===2?'🥈':'🥉')+'</div></div>'; }).join("");
  const rest=lb.slice(3).map((p,i)=>'<div class="sb-row"><div class="rk">'+(i+4)+'</div><div class="av" style="background:'+avColor(i+3)+'">'+avEmoji(p.avatar)+'</div><div class="nm">'+esc(p.name)+'</div><div class="sc">'+p.score+'</div></div>').join("");
  return '<div class="podium-screen">'
    +'<h2>🏆 '+esc(t("podium_title"))+'</h2>'+(top[0]?'<div style="text-align:center;color:var(--accent);font-weight:800;font-size:18px">'+esc(t("winner_is"))+': '+esc(top[0].name)+'</div>':'')+(s.teams?'<div class="team-standings"><div class="ts-title">👥 '+esc(t("team_standings"))+'</div>'+s.teams.map(function(tt,i){return '<div class="ts-row'+(i===0?" win":"")+'" style="--tc:'+tt.color+'"><div class="ts-rk">'+(i+1)+'</div><div class="ts-emoji">'+tt.emoji+'</div><div class="ts-name">'+esc(tt.name)+'</div><div class="ts-sc">'+tt.score+'</div></div>';}).join("")+'</div>':'')
    +'<div class="podium">'+pod+'</div>'+(rest?'<div class="rest-list">'+rest+'</div>':'')
    +'<div class="cta-row"><button class="btn btn-primary btn-lg" data-action="grestart">↻ '+esc(t("game_replay"))+'</button><button class="btn btn-ghost btn-lg" data-action="greport">📊 '+esc(t("game_report"))+'</button><button class="btn btn-ghost btn-lg" data-action="livestop">'+esc(t("live_back"))+'</button></div>'
  +'</div>';
}

/* ---------- player ---------- */
function afterGameJoin(){ const j=state.join; if(!j) return;
  { const ti=document.getElementById("gp-type"); if(ti){ ti.focus(); ti.onkeydown=(e)=>{ if(e.key==="Enter"){ e.preventDefault(); const v=(ti.value||"").trim(); if(v) gameAnswerText(v); else toast(t("type_need_answer")); } }; } }
  if(j.pid){ if(!state._poll) startGameLoops(gamePlayerPoll,gamePlayerTick); setRem(j.session); }
  else { const n=document.getElementById("gj-name"); if(n){ n.addEventListener("keydown",ev=>{ if(ev.key==="Enter"){ ev.preventDefault(); gameJoinSubmit(); } }); n.focus(); } } }
function gamePlayerPoll(){ const j=state.join; if(!j||!j.pid) return; api("live_get",{query:{code:j.code,pid:j.pid}}).then(d=>{ if(!state.join||state.join.code!==j.code) return; state.join.session=d.session;
  const key=d.session.phase+":"+d.session.qIndex; if(key!==state._gkey){ state._gkey=key; setRem(d.session); render(); } else { setRem(d.session); updateGamePlayerDynamic(); } })
  .catch(e=>{ if(e.status===404){ stopPoll(); if(state.join){ state.join.error=t("live_notfound"); render(); } } }); }
function gamePlayerTick(){ const j=state.join; const s=j&&j.session; if(!s||s.phase!=="question"||!s.question) return; const rem=gameRemaining();
  const n=document.getElementById("gp-ring-num"); if(n) n.textContent=Math.ceil(rem/1000);
  const r=document.getElementById("gp-ring"); if(r) r.style.setProperty("--deg",(360*rem/((s.question.time||20)*1000))+"deg");
  if(rem<=0){ document.querySelectorAll(".gp-ans").forEach(b=>b.disabled=true); } }
function updateGamePlayerDynamic(){ const j=state.join; const s=j&&j.session; if(!s) return;
  if(s.phase==="lobby"){ const c=document.getElementById("gp-count"); if(c) c.textContent=s.count; }
  else if(s.phase==="question"){ const a=document.getElementById("gp-answered"); if(a&&s.question) a.textContent=s.question.answeredCount; } }
function gameJoinSubmit(){ const j=state.join; const el=document.getElementById("gj-name"); const name=((el&&el.value)||"").trim(); if(!name){ toast(t("game_need_name")); return; }
  j.name=name; try{ localStorage.setItem(STORE_KEY+"_pname",name); }catch(e){}
  j.busy=true; render();
  api("live_join",{body:{code:j.code,name:name,avatar:j.avatar||0,team:(j.team!=null?j.team:0)}}).then(d=>{ if(!state.join) return; j.pid=d.pid; j.session=d.session; j.busy=false; try{ localStorage.setItem(STORE_KEY+"_pid_"+j.code,d.pid); }catch(e){} state._gkey=d.session.phase+":"+d.session.qIndex; setRem(d.session); render(); startGameLoops(gamePlayerPoll,gamePlayerTick); })
    .catch(e=>{ j.busy=false; toast(e.status===423?t("game_lobby_closed"):apiErr(e)); render(); }); }
function gameAnswerText(text){ const j=state.join; const s=j&&j.session; if(!s||s.phase!=="question"||!s.question) return; if(s.question.answered) return; if(gameRemaining()<=0){ toast(t("game_times_up")); return; }
  const btn=document.querySelector('[data-action="gtype"]'); if(btn) btn.disabled=true; const inp=document.getElementById("gp-type"); if(inp) inp.disabled=true;
  api("live_answer",{body:{code:s.code,pid:j.pid,text:text}}).then(()=>{ if(s.question){ s.question.answered=true; s.question.myText=text; } render(); })
    .catch(e=>{ toast(apiErr(e)); if(btn) btn.disabled=false; if(inp) inp.disabled=false; }); }
function gameAnswer(i){ const j=state.join; const s=j&&j.session; if(!s||s.phase!=="question"||!s.question) return; if(s.question.answered) return; if(gameRemaining()<=0){ toast(t("game_times_up")); return; }
  document.querySelectorAll(".gp-ans").forEach(b=>b.disabled=true); const btn=document.querySelector('.gp-ans[data-i="'+i+'"]'); if(btn) btn.classList.add("chosen");
  api("live_answer",{body:{code:s.code,pid:j.pid,choice:i}}).then(()=>{ if(s.question){ s.question.answered=true; s.question.myChoice=i; } render(); })
    .catch(e=>{ toast(apiErr(e)); document.querySelectorAll(".gp-ans").forEach(b=>b.disabled=false); }); }

function viewGameJoin(){ const j=state.join; const s=j.session;
  if(j.error) return joinErrHTML(j.error);
  if(!s) return '<div class="join-view"><div class="join-loading">'+esc(t("live_loading"))+'</div></div>';
  if(!j.pid) return gamePlayerJoinForm(j,s);
  if(s.phase==="lobby") return gamePlayerLobby(s,j);
  if(s.phase==="question") return gamePlayerQuestion(s,j);
  if(s.phase==="reveal") return gamePlayerReveal(s,j);
  if(s.phase==="final") return gamePlayerFinal(s,j);
  return ''; }
function joinErrHTML(msg){ return '<div class="join-view"><div class="jv-head"><a class="backlink" data-action="joinleave">←</a></div><div class="join-err">⚠ '+esc(msg)+'<div style="margin-top:16px"><button class="btn btn-primary" data-action="joinleave">'+esc(t("back_home"))+'</button></div></div></div>'; }
function gamePlayerJoinForm(j,s){ const avs=AVATARS.map((e,i)=>'<button class="gj-av'+((j.avatar||0)===i?" on":"")+'" data-action="gavatar" data-i="'+i+'">'+e+'</button>').join("");
  return '<div class="join-view game-join"><div class="jv-head"><a class="backlink" data-action="joinleave">←</a><span class="jv-code">'+esc(s.code)+'</span></div>'
    +'<div class="gj-card"><div class="gj-title">🎮 '+esc(s.prompt)+'</div><div class="gj-sub">'+esc(t("game_pick_name"))+'</div>'
    +'<div class="nick-row"><input id="gj-name" class="input big-input" maxlength="20" placeholder="'+esc(t("game_nickname"))+'" value="'+esc(j.name||"")+'"><button class="nick-btn" data-action="nickgen" data-t="gj-name" title="'+esc(t("nick_gen"))+'">🎲</button></div>'
    +'<div class="gj-avs">'+avs+'</div>'
    +((s.teamList&&s.teamList.length)?'<div class="gj-tlabel">'+esc(t("team_pick"))+'</div><div class="gj-teams">'+s.teamList.map(function(tm){return '<button class="gj-team'+(((j.team||0)===tm.idx)?" on":"")+'" data-action="gteam" data-i="'+tm.idx+'" style="--tc:'+tm.color+'">'+tm.emoji+' '+esc(tm.name)+'</button>';}).join("")+'</div>':'')
    +'<button class="btn btn-primary btn-block" data-action="gjoin"'+(j.busy?' disabled':'')+'>'+(j.busy?"…":"🚀 "+esc(t("game_enter")))+'</button></div></div>'; }
function gamePlayerLobby(s,j){ return '<div class="join-view game-wait"><div class="gw-card"><div class="gw-av">'+avEmoji(j.avatar)+'</div><div class="gw-name">'+esc(j.name)+'</div>'+(s.myTeam?'<div class="gw-team" style="--tc:'+s.myTeam.color+'">'+s.myTeam.emoji+' '+esc(s.myTeam.name)+'</div>':'')+'<div class="gw-msg">'+esc(t("game_youre_in"))+'</div><div class="gw-sub">'+esc(t("game_look_screen"))+'</div><div class="gw-count"><b id="gp-count">'+s.count+'</b> '+esc(t("game_players"))+'</div></div>'+reactBar()+'</div>'; }
function gamePlayerQuestion(s,j){ const q=s.question;
  if(q.answered){ const ic=q.isText?'✍️':SHAPES[q.myChoice!=null?q.myChoice:0]; const yourTxt=(q.isText&&q.myText)?'<div class="gp-lock-your">“'+esc(q.myText)+'”</div>':''; return '<div class="join-view game-play"><div class="gp-head"><span class="pill">📋 '+(q.index+1)+'/'+s.total+'</span><span class="pill">✓ <b id="gp-answered">'+q.answeredCount+'</b>/'+s.count+'</span></div><div class="gp-locked"><div class="gp-lock-ic">'+ic+'</div>'+yourTxt+'<div class="gp-lock-msg">'+esc(t("game_locked"))+'</div><div class="gp-lock-sub">'+esc(t("game_wait_others"))+'</div></div>'+reactBar()+'</div>'; }
  const head='<div class="join-view game-play"><div class="gp-head"><span class="pill">📋 '+(q.index+1)+'/'+s.total+'</span><span class="ring sm" id="gp-ring" style="--deg:360deg"><span id="gp-ring-num">'+Math.ceil((q.remaining||0)/1000)+'</span></span></div><h2 class="gp-q">'+esc(q.text)+'</h2>';
  if(q.isText){ return head+'<div class="gp-type-wrap"><input id="gp-type" class="input gp-type-input" placeholder="'+esc(q.isNum?t("num_answer_ph"):t("type_answer_ph"))+'" '+(q.isNum?'inputmode="decimal" ':'')+'maxlength="90" autocomplete="off" autocapitalize="off" spellcheck="false"><button class="btn btn-primary btn-block" data-action="gtype">'+esc(t("type_submit"))+' ▶</button></div></div>'; }
  const tiles=(q.answers||[]).map((a,i)=>'<button class="ans gp-ans '+ANSCLASS[i]+'" data-action="gans" data-i="'+i+'"><span class="ico">'+SHAPES[i]+'</span><span>'+esc(a.text)+'</span></button>').join("");
  return head+'<div class="answers gp-answers'+(q.type==="tf"?" two":"")+'">'+tiles+'</div></div>'; }
function gamePlayerReveal(s,j){ const r=s.myResult||{}; const ok=r.correct; const q=s.question||{}; const canon=(q.isText&&q.canonical)?'<div class="gres-canon">'+esc(t("correct_was"))+' <b>'+esc(q.canonical)+'</b></div>':'';
  return '<div class="join-view game-result"><div class="gres '+(ok?'ok':(r.answered?'no':'miss'))+'">'
    +'<div class="gres-ic">'+(ok?'✓':(r.answered?'✗':'⏱'))+'</div><div class="gres-msg">'+esc(ok?t("game_correct"):(r.answered?t("game_wrong"):t("game_missed")))+'</div>'
    +(ok?'<div class="gres-pts">+'+(r.points||0)+'</div>':'')+canon+((ok&&r.streak>=2)?'<div class="gres-streak">🔥 '+esc(t("streak",r.streak))+'</div>':'')
    +'<div class="gres-rank">'+esc(t("game_your_rank"))+' <b>#'+(r.rank||"–")+'</b> · '+(r.score||0)+' '+esc(t("stat_pts"))+'</div></div>'+reactBar()+'</div>'; }
function gamePlayerFinal(s,j){ const rank=s.myRank||"–"; const me=s.me||{}; const lb=s.leaderboard||[]; const top=lb.slice(0,3); const tRank=(s.teams&&s.myTeam)?(s.teams.findIndex(function(t){return t.idx===s.myTeam.idx;})+1):0;
  const pod=top.map((p,i)=>{ const place=i+1; return '<div class="pod p'+place+'"><div class="av" style="background:'+avColor(i)+'">'+avEmoji(p.avatar)+'</div><div class="nm">'+esc(p.name)+'</div><div class="sc">'+p.score+'</div><div class="bar">'+(place===1?'🥇':place===2?'🥈':'🥉')+'</div></div>'; }).join("");
  return '<div class="join-view game-result"><div class="gres '+(rank===1?'ok':'')+'"><div class="gres-ic">🏁</div><div class="gres-msg">'+esc(t("game_finished"))+'</div><div class="gres-rank">'+esc(t("game_your_rank"))+' <b>#'+rank+'</b> / '+(s.totalPlayers||lb.length)+' · '+(me.score||0)+' '+esc(t("stat_pts"))+'</div>'+(tRank>0?'<div class="gres-team" style="--tc:'+s.myTeam.color+'">'+s.myTeam.emoji+' '+esc(s.myTeam.name)+' · #'+tRank+'/'+s.teams.length+'</div>':'')+'</div>'
    +(pod?'<div class="podium" style="margin-top:18px">'+pod+'</div>':'')+'<div class="cta-row"><button class="btn btn-ghost btn-lg" data-action="joinleave">'+esc(t("back_home"))+'</button></div></div>'; }

/* ============================ POST-GAME REPORT ============================ */
function fmtTime(t){ return (t==null)?"–":(t.toFixed(1)+"s"); }
function fetchReport(){ const s=state.live; if(!s) return; api("live_report",{query:{code:s.code}}).then(d=>{ state.report=d.report; state.screen="report"; render(); }).catch(e=>toast(apiErr(e))); }
function computeReport(rep){
  const Q=rep.questions||[], P=rep.players||[], total=rep.total||Q.length, count=P.length; const qStarts=rep.qStarts||{};
  const perQ=Q.map((q,qi)=>{ let responders=0,correct=0,timeSum=0,timeN=0; const dist=q.answers.map(()=>0);
    P.forEach(p=>{ const a=p.answers?p.answers[qi]:null; if(a){ responders++; if(a.correct) correct++; if(typeof a.choice==="number"&&a.choice>=0&&a.choice<dist.length) dist[a.choice]++;
      const st=qStarts[qi]; if(st&&a.ts){ const dt=(a.ts-st)/1000; if(dt>=0&&dt<3600){ timeSum+=dt; timeN++; } } } });
    return { text:q.text, type:q.type, correctIndex:q.correctIndex, answers:q.answers, responders:responders, correct:correct, accuracy:count?Math.round(correct/count*100):0, dist:dist, avgTime:timeN?(timeSum/timeN):null }; });
  const players=P.map(p=>{ const grid=[],pts=[]; Q.forEach((q,qi)=>{ const a=p.answers?p.answers[qi]:null; grid.push(a?(a.correct?1:0):-1); pts.push(a?(a.points||0):0); });
    return { id:p.id, name:p.name, avatar:p.avatar, score:p.score, correct:p.correct||0, best:p.best||0, accuracy: total?Math.round((p.correct||0)/total*100):0, grid:grid, pts:pts }; }).sort((a,b)=>b.score-a.score);
  const totalCorrect=players.reduce((s,p)=>s+p.correct,0);
  const avgScore=count?Math.round(players.reduce((s,p)=>s+p.score,0)/count):0;
  const overallAcc=(count&&total)?Math.round(totalCorrect/(count*total)*100):0;
  const ts=perQ.filter(q=>q.avgTime!=null).map(q=>q.avgTime); const avgTime=ts.length?(ts.reduce((a,b)=>a+b,0)/ts.length):null;
  return { prompt:rep.prompt, code:rep.code, total:total, count:count, perQ:perQ, players:players, avgScore:avgScore, overallAcc:overallAcc, avgTime:avgTime, totalCorrect:totalCorrect };
}
const REPORT_COLS=["#ffd23f","#ff4e8a","#2ee6c4","#2f6bff"];
function viewReport(){
  if(!state.report) return '<div class="wrap"><div class="join-loading">…</div></div>';
  const R=computeReport(state.report); state._rc=R;
  if(!R.count) return '<div class="wrap report-wrap"><div class="report-head no-print"><a class="backlink" data-action="reportback">← '+esc(t("report_back"))+'</a></div><div class="live-empty" style="min-height:200px">'+esc(t("report_no_data"))+'</div></div>';
  const cards='<div class="rcards">'
    +'<div class="rcard"><div class="rc-v">'+R.count+'</div><div class="rc-l">'+esc(t("report_players"))+'</div></div>'
    +'<div class="rcard"><div class="rc-v">'+R.total+'</div><div class="rc-l">'+esc(t("report_questions"))+'</div></div>'
    +'<div class="rcard"><div class="rc-v">'+R.avgScore+'</div><div class="rc-l">'+esc(t("report_avg_score"))+'</div></div>'
    +'<div class="rcard"><div class="rc-v">'+R.overallAcc+'%</div><div class="rc-l">'+esc(t("report_accuracy"))+'</div></div>'
    +'<div class="rcard"><div class="rc-v">'+fmtTime(R.avgTime)+'</div><div class="rc-l">'+esc(t("report_avg_time"))+'</div></div>'
  +'</div>';
  const qlist=R.perQ.map((q,i)=>{
    const diff = q.accuracy>=70?['easy','🟢',t("report_easy")] : (q.accuracy>=40?['med','🟡',t("report_med")] : ['hard','🔴',t("report_hard")]);
    const maxd=Math.max.apply(null,[1].concat(q.dist));
    let dist='';
    if(q.type==='quiz'||q.type==='tf'){ dist='<div class="rq-dist">'+q.answers.map((a,ai)=>'<div class="rq-opt'+(ai===q.correctIndex?' ok':'')+'"><span class="rq-ol">'+(ai===q.correctIndex?'✓ ':'')+esc(a.text)+'</span><span class="rq-ob"><i style="width:'+Math.round((q.dist[ai]||0)/maxd*100)+'%;background:'+(REPORT_COLS[ai]||'#888')+'"></i></span><b>'+(q.dist[ai]||0)+'</b></div>').join("")+'</div>'; }
    return '<div class="rq"><div class="rq-top"><span class="rq-n">Q'+(i+1)+'</span><span class="rq-txt">'+esc(q.text)+'</span><span class="rq-diff '+diff[0]+'">'+diff[1]+' '+q.accuracy+'%</span></div>'
      +'<div class="rq-bar"><i style="width:'+q.accuracy+'%"></i></div>'
      +'<div class="rq-meta">'+q.correct+'/'+R.count+' '+esc(t("report_correct"))+' · ⏱ '+fmtTime(q.avgTime)+' · '+q.responders+' '+esc(t("report_answered"))+'</div>'+dist+'</div>';
  }).join("");
  const qhead=R.perQ.map((q,i)=>'<div class="rt-q" title="'+esc(q.text)+'">Q'+(i+1)+'</div>').join("");
  const rows=R.players.map((p,idx)=>{ const cells=p.grid.map(g=>'<div class="rt-cell '+(g===1?'ok':(g===0?'no':'none'))+'">'+(g===1?'✓':(g===0?'✗':'·'))+'</div>').join("");
    return '<div class="rt-row"><div class="rt-rank">'+(idx+1)+'</div><div class="rt-av" style="background:'+avColor(idx)+'">'+avEmoji(p.avatar)+'</div><div class="rt-name">'+esc(p.name)+'</div><div class="rt-score">'+p.score+'</div><div class="rt-acc">'+p.accuracy+'%</div><div class="rt-grid">'+cells+'</div></div>'; }).join("");
  const table='<div class="rtable-wrap"><div class="rtable"><div class="rt-row rt-head"><div class="rt-rank">#</div><div class="rt-av"></div><div class="rt-name">'+esc(t("report_player"))+'</div><div class="rt-score">'+esc(t("stat_pts"))+'</div><div class="rt-acc">%</div><div class="rt-grid">'+qhead+'</div></div>'+rows+'</div></div>';
  return '<div class="wrap report-wrap">'
    +'<div class="report-head no-print"><a class="backlink" data-action="reportback">← '+esc(t("report_back"))+'</a><div class="report-actions"><button class="btn btn-ghost sm" data-action="report_csv">⬇ '+esc(t("report_csv"))+'</button><button class="btn btn-ghost sm" data-action="report_json">⬇ '+esc(t("report_json"))+'</button><button class="btn btn-ghost sm" data-action="report_print">🖨 '+esc(t("report_print"))+'</button></div></div>'
    +'<h2 class="report-title">📊 '+esc(t("report_title"))+': '+esc(R.prompt)+'</h2>'
    +cards
    +'<h3 class="report-h3">'+esc(t("report_byq"))+'</h3><div class="rq-list">'+qlist+'</div>'
    +'<h3 class="report-h3">'+esc(t("report_byp"))+'</h3>'+table
  +'</div>';
}
function csvEsc(v){ v=String(v==null?"":v); return /[",\n;]/.test(v)?'"'+v.replace(/"/g,'""')+'"':v; }
function exportReportCSV(){ const R=state._rc; if(!R) return; const L=[];
  const head=["Rank","Player","Score","Accuracy%","Correct","Total","BestStreak"]; R.perQ.forEach((q,i)=>head.push("Q"+(i+1)));
  L.push(head.map(csvEsc).join(","));
  R.players.forEach((p,idx)=>{ const row=[idx+1,p.name,p.score,p.accuracy,p.correct,R.total,p.best]; p.pts.forEach(pt=>row.push(pt)); L.push(row.map(csvEsc).join(",")); });
  L.push(""); L.push(["Question","Accuracy%","Correct","Responders","AvgTime_s"].map(csvEsc).join(","));
  R.perQ.forEach((q,i)=>{ L.push(["Q"+(i+1)+": "+q.text, q.accuracy, q.correct, q.responders, q.avgTime!=null?q.avgTime.toFixed(1):""].map(csvEsc).join(",")); });
  liveDL(new Blob(["\ufeff"+L.join("\n")],{type:"text/csv;charset=utf-8"}), "raport-"+R.code+".csv");
}
function exportReportJSON(){ const R=state._rc; if(!R) return; liveDL(new Blob([JSON.stringify(R,null,2)],{type:"application/json"}), "raport-"+R.code+".json"); }

/* ============================ SELF-PACED (homework) MODE ============================ */
function createAssign(){ if(!onlineOnly()) return; if(!requireAdmin()) return; const quiz=maybeShuffleQuiz(resolveQuizForGame(state.setup.quiz));
  api("live_create",{body:{type:"assign", quiz:quiz}}).then(d=>{ state.live=d.session; state.liveRole="host"; state.screen="livehost"; render(); }).catch(e=>toast(apiErr(e))); }

/* host */
function afterAssignHost(){ const s=state.live; if(!s) return; const q=document.getElementById("a-qr"); if(q) qrInto(q, joinURLFor(s.code), 6); startPoll(assignHostPoll); }
function assignHostPoll(){ const s=state.live; if(!s) return; api("live_get",{query:{code:s.code}}).then(d=>{ if(!state.live) return; state.live=d.session; updateAssignHost(); }).catch(()=>{}); }
function updateAssignHost(){ const s=state.live; if(!s) return; const e=id=>document.getElementById(id);
  if(e("a-done")) e("a-done").textContent=s.doneCount; if(e("a-count")) e("a-count").textContent=s.count; if(e("a-avg")) e("a-avg").textContent=s.avgScore;
  if(e("a-lb")) e("a-lb").innerHTML=assignLbHTML(s);
  const st=e("a-status"); if(st){ st.textContent=s.open?t("live_open"):t("live_closed_b"); st.className="lh-status "+(s.open?"open":"closed"); } }
function assignLbHTML(s){ const L=s.leaderboard||[]; if(!L.length) return '<div class="g-lobby-empty">'+esc(t("assign_none_done"))+'</div>';
  return L.map((p,i)=>'<div class="sb-row"><div class="rk">'+(i+1)+'</div><div class="av" style="background:'+avColor(i)+'">'+avEmoji(p.avatar)+'</div><div class="nm">'+esc(p.name)+'</div><div class="sc">'+p.score+'</div></div>').join(""); }
function viewAssignHost(){ const s=state.live; if(!s) return '';
  return '<div class="game-host">'
    +'<div class="lh-bar"><a class="backlink" data-action="livestop">← '+esc(t("live_back"))+'</a><div class="lh-meta"><span class="lh-type">📝 '+esc(s.prompt)+'</span><span id="a-status" class="lh-status '+(s.open?'open':'closed')+'">'+esc(s.open?t("live_open"):t("live_closed_b"))+'</span></div>'
      +'<div class="lh-ctrls"><button class="btn btn-ghost sm" data-action="'+(s.open?'aclose':'aopen')+'">'+(s.open?'⏸ '+esc(t("live_pause")):'▶ '+esc(t("live_resume")))+'</button><button class="btn btn-ghost sm" data-action="greport">📊 '+esc(t("game_report"))+'</button><button class="btn btn-ghost sm danger" data-action="liveenda">⏹ '+esc(t("live_end"))+'</button></div></div>'
    +'<div class="gl-main">'
      +'<div class="join-card gl-join"><div class="jc-label">'+esc(t("live_join_at"))+'</div><div class="jc-host">'+esc(location.host+location.pathname)+'</div><div class="jc-code">'+esc(s.code)+'</div><div id="a-qr" class="jc-qr"></div></div>'
      +'<div class="gl-players"><div class="a-stats"><div class="a-stat"><b id="a-done">'+s.doneCount+'</b><span>'+esc(t("assign_done"))+'</span></div><div class="a-stat"><b id="a-count">'+s.count+'</b><span>'+esc(t("assign_joined"))+'</span></div><div class="a-stat"><b id="a-avg">'+s.avgScore+'</b><span>'+esc(t("report_avg_score"))+'</span></div></div>'
        +'<div class="gl-ptitle">'+esc(t("scoreboard"))+'</div><div id="a-lb">'+assignLbHTML(s)+'</div></div>'
    +'</div>'
  +'</div>';
}

/* player */
function afterAssignJoin(){ const j=state.join; if(!j) return;
  { const ti=document.getElementById("ap-type"); if(ti){ ti.focus(); ti.onkeydown=(e)=>{ if(e.key==="Enter"){ e.preventDefault(); const v=(ti.value||"").trim(); if(v) assignAnswerText(v); else toast(t("type_need_answer")); } }; } }
  if(!j.pid){ const n=document.getElementById("aj-name"); if(n){ n.addEventListener("keydown",ev=>{ if(ev.key==="Enter"){ ev.preventDefault(); assignJoinSubmit(); } }); n.focus(); } } }
function fetchAssign(){ const j=state.join; if(!j) return; api("live_get",{query:{code:j.code,pid:j.pid||undefined}}).then(d=>{ if(!state.join||state.join.code!==j.code) return; j.session=d.session; j.error=null; render(); }).catch(e=>{ if(!state.join||state.join.code!==j.code) return; j.error=(e.status===404)?t("live_notfound"):apiErr(e); render(); }); }
function assignJoinSubmit(){ const j=state.join; const el=document.getElementById("aj-name"); const name=((el&&el.value)||"").trim(); if(!name){ toast(t("game_need_name")); return; }
  j.name=name; try{ localStorage.setItem(STORE_KEY+"_pname",name); }catch(e){}
  j.busy=true; render();
  api("live_join",{body:{code:j.code,name:name,avatar:j.avatar||0,team:(j.team!=null?j.team:0)}}).then(d=>{ if(!state.join) return; j.pid=d.pid; j.session=d.session; j.busy=false; try{ localStorage.setItem(STORE_KEY+"_pid_"+j.code,d.pid); }catch(e){} render(); })
    .catch(e=>{ j.busy=false; toast(e.status===423?t("assign_closed"):apiErr(e)); render(); }); }
function assignAnswer(i){ const j=state.join; const s=j&&j.session; if(!s||!s.current||j.busy) return; const qi=s.current.index; j.busy=true;
  document.querySelectorAll(".ap-ans").forEach(b=>b.disabled=true); const btn=document.querySelector('.ap-ans[data-i="'+i+'"]'); if(btn) btn.classList.add("chosen");
  api("live_answer",{body:{code:j.code,pid:j.pid,qi:qi,choice:i}}).then(d=>{ j.busy=false; j.feedback={choice:i,correctIndex:d.correctIndex,correct:d.correct,points:d.points,done:d.done}; render(); })
    .catch(e=>{ j.busy=false; toast(apiErr(e)); document.querySelectorAll(".ap-ans").forEach(b=>b.disabled=false); }); }
function assignNext(){ const j=state.join; if(!j) return; j.feedback=null; fetchAssign(); }
function viewAssignPlay(){ const j=state.join; const s=j.session;
  if(j.error) return joinErrHTML(j.error);
  if(!s) return '<div class="join-view"><div class="join-loading">'+esc(t("live_loading"))+'</div></div>';
  if(!j.pid || s.joined===false) return assignJoinForm(j,s);
  if(j.feedback) return assignFeedback(j,s);
  if(s.done) return assignDone(s);
  if(s.current) return assignQuestion(j,s);
  if(!s.open) return joinErrHTML(t("assign_closed_note"));
  return '<div class="join-view"><div class="join-loading">'+esc(t("live_loading"))+'</div></div>';
}
function assignJoinForm(j,s){ const avs=AVATARS.map((e,i)=>'<button class="gj-av'+((j.avatar||0)===i?" on":"")+'" data-action="gavatar" data-i="'+i+'">'+e+'</button>').join("");
  return '<div class="join-view game-join"><div class="jv-head"><a class="backlink" data-action="joinleave">←</a><span class="jv-code">'+esc(s.code)+'</span></div><div class="gj-card"><div class="gj-title">📝 '+esc(s.prompt)+'</div><div class="gj-sub">'+esc(t("assign_intro",s.total))+'</div><div class="nick-row"><input id="aj-name" class="input big-input" maxlength="20" placeholder="'+esc(t("game_nickname"))+'" value="'+esc(j.name||"")+'"><button class="nick-btn" data-action="nickgen" data-t="aj-name" title="'+esc(t("nick_gen"))+'">🎲</button></div><div class="gj-avs">'+avs+'</div><button class="btn btn-primary btn-block" data-action="ajoin"'+(j.busy?' disabled':'')+'>'+(j.busy?"…":"🚀 "+esc(t("assign_start")))+'</button></div></div>'; }
function assignQuestion(j,s){ const q=s.current;
  const head='<div class="join-view game-play"><div class="gp-head"><span class="pill">📋 '+(q.index+1)+'/'+q.total+'</span><span class="pill">⭐ '+(s.me?s.me.score:0)+'</span></div><h2 class="gp-q">'+esc(q.text)+'</h2>';
  if(q.isText){ return head+'<div class="gp-type-wrap"><input id="ap-type" class="input gp-type-input" placeholder="'+esc(q.isNum?t("num_answer_ph"):t("type_answer_ph"))+'" '+(q.isNum?'inputmode="decimal" ':'')+'maxlength="90" autocomplete="off" autocapitalize="off" spellcheck="false"><button class="btn btn-primary btn-block" data-action="atype">'+esc(t("type_submit"))+' ▶</button></div></div>'; }
  const tiles=q.answers.map((a,i)=>'<button class="ans ap-ans '+ANSCLASS[i]+'" data-action="aans" data-i="'+i+'"><span class="ico">'+SHAPES[i]+'</span><span>'+esc(a.text)+'</span></button>').join("");
  return head+'<div class="answers ap-answers'+(q.type==="tf"?" two":"")+'">'+tiles+'</div></div>'; }
function assignFeedback(j,s){ const f=j.feedback; const q=s.current||{answers:[],type:"quiz"};
  let body;
  if(q.isText || f.canonical!=null){ body='<div class="ap-canon">'+esc(t("correct_was"))+' <b>'+esc(f.canonical||"")+'</b></div>'; }
  else { const tiles=(q.answers||[]).map((a,i)=>{ let cls=ANSCLASS[i]; if(i===f.correctIndex) cls+=' correct'; else if(i===f.choice&&!f.correct) cls+=' wrong'; else cls+=' dim'; return '<div class="ans '+cls+'"><span class="ico">'+SHAPES[i]+'</span><span>'+esc(a.text)+'</span></div>'; }).join(""); body='<div class="answers'+(q.type==="tf"?" two":"")+' showonly">'+tiles+'</div>'; }
  return '<div class="join-view game-play"><div class="ap-verdict '+(f.correct?'ok':'no')+'">'+(f.correct?'✓ '+esc(t("game_correct"))+' +'+(f.points||0):'✗ '+esc(t("game_wrong")))+'</div>'+body+'<button class="btn btn-primary btn-block" style="margin-top:16px" data-action="anext">'+(f.done?'🎉 '+esc(t("assign_finish")):esc(t("continue"))+' ▶')+'</button></div>'; }
function assignAnswerText(text){ const j=state.join; const s=j&&j.session; if(!s||!s.current||j.busy) return; const qi=s.current.index; j.busy=true;
  const btn=document.querySelector('[data-action="atype"]'); if(btn) btn.disabled=true;
  api("live_answer",{body:{code:j.code,pid:j.pid,qi:qi,text:text}}).then(d=>{ j.busy=false; j.feedback={choice:-1,correctIndex:-1,correct:d.correct,points:d.points,done:d.done,canonical:d.canonical}; render(); })
    .catch(e=>{ j.busy=false; toast(apiErr(e)); if(btn) btn.disabled=false; }); }
function assignDone(s){ const r=s.result||{};
  return '<div class="join-view game-result"><div class="gres ok"><div class="gres-ic">🎉</div><div class="gres-msg">'+esc(t("assign_complete"))+'</div><div class="gres-pts">'+(r.score||0)+'</div><div class="gres-rank">'+esc(t("game_your_rank"))+' <b>#'+(r.rank||'–')+'</b> / '+(r.finishers||0)+' · '+(r.correct||0)+'/'+(r.total||0)+' '+esc(t("report_correct"))+'</div></div><div class="cta-row"><button class="btn btn-ghost btn-lg" data-action="joinleave">'+esc(t("back_home"))+'</button></div></div>'; }

/* ============================ PWA: install + offline ============================ */
let _deferredInstall=null;
function onlineOnly(){ if(navigator.onLine) return true; toast(t("offline_feature")); return false; }
function updatePwaBar(){ const bar=document.getElementById("pwa-bar"); if(!bar) return;
  if(!navigator.onLine){ bar.className="pwa-bar off"; bar.innerHTML='<span>📴 '+esc(t("offline_banner"))+'</span>'; return; }
  if(_deferredInstall){ bar.className="pwa-bar install"; bar.innerHTML='<span>📲 '+esc(t("install_hint"))+'</span><button class="pwa-btn" data-action="pwainstall">'+esc(t("install_btn"))+'</button><button class="pwa-x" data-action="pwadismiss" aria-label="x">✕</button>'; return; }
  bar.className="pwa-bar hidden"; bar.innerHTML=""; }
function pwaInstall(){ if(!_deferredInstall) return; const d=_deferredInstall; _deferredInstall=null; updatePwaBar(); try{ d.prompt(); }catch(e){} }
function initPWA(){
  if("serviceWorker" in navigator){ try{ navigator.serviceWorker.register("?asset=sw").catch(()=>{}); }catch(e){} }
  window.addEventListener("beforeinstallprompt",ev=>{ ev.preventDefault(); _deferredInstall=ev; updatePwaBar(); });
  window.addEventListener("appinstalled",()=>{ _deferredInstall=null; updatePwaBar(); });
  window.addEventListener("online",()=>{ updatePwaBar(); toast(t("online_back")); });
  window.addEventListener("offline",()=>{ updatePwaBar(); });
  updatePwaBar();
}
/* ---------------- CONFETTI ---------------- */
let confettiRAF=null;
function launchConfetti(){
  const cv=document.getElementById("confetti"); if(!cv) return;
  cv.classList.remove("hidden");
  const ctx=cv.getContext("2d");
  const dpr=Math.min(window.devicePixelRatio||1,2);
  function size(){ cv.width=innerWidth*dpr; cv.height=innerHeight*dpr; }
  size();
  const colors=["#ffd23f","#ff4e8a","#2ee6c4","#2f6bff","#18bd6b","#e8385a","#f7a823"];
  const N=140;
  const parts=Array.from({length:N},()=>({
    x:Math.random()*cv.width, y:-Math.random()*cv.height*0.4,
    r:(4+Math.random()*6)*dpr, c:colors[Math.floor(Math.random()*colors.length)],
    vx:(-1+Math.random()*2)*dpr, vy:(2+Math.random()*3.5)*dpr,
    rot:Math.random()*6.28, vr:(-0.2+Math.random()*0.4), shape:Math.random()<0.5
  }));
  const start=performance.now();
  if(confettiRAF) cancelAnimationFrame(confettiRAF);
  function frame(now){
    ctx.clearRect(0,0,cv.width,cv.height);
    parts.forEach(p=>{
      p.x+=p.vx; p.y+=p.vy; p.vy+=0.03*dpr; p.rot+=p.vr;
      if(p.y>cv.height+20){ p.y=-20; p.vy=(2+Math.random()*3)*dpr; p.x=Math.random()*cv.width; }
      ctx.save(); ctx.translate(p.x,p.y); ctx.rotate(p.rot); ctx.fillStyle=p.c;
      if(p.shape) ctx.fillRect(-p.r/2,-p.r/2,p.r,p.r*1.6);
      else { ctx.beginPath(); ctx.arc(0,0,p.r/1.6,0,6.28); ctx.fill(); }
      ctx.restore();
    });
    if(now-start<6000){ confettiRAF=requestAnimationFrame(frame); }
    else { ctx.clearRect(0,0,cv.width,cv.height); cv.classList.add("hidden"); }
  }
  confettiRAF=requestAnimationFrame(frame);
}

/* =========================================================================
   ACTIONS (event delegation)
   ========================================================================= */
document.addEventListener("click",(e)=>{
  const el=e.target.closest("[data-action]"); 
  // modal background click-to-close
  const bg=e.target.closest('[data-action="closemodal-bg"]');
  if(bg && !e.target.closest('[data-stop]')){ state.modal=null; renderModal(); return; }
  if(!el) return;
  const a=el.dataset.action; const id=el.dataset.id; const v=el.dataset.v;
  Sound.ensure(); // unlock audio on first gesture

  switch(a){
    case "spinner": stopPoll(); if(!state.spinner.items||!state.spinner.items.length) spinLoad(); state.spinner.result=null; state.spinAngle=0; state.screen="spinner"; render(); break;
    case "help": state.helpBook="user"; state.screen="help"; render(); break;
    case "helpbook": state.helpBook=el.dataset.b; render(); break;
    case "mdjump": { var tgt=document.getElementById(el.dataset.t); if(tgt) tgt.scrollIntoView({behavior:"smooth",block:"start"}); break; }
    case "spinspin": spinWheel(); break;
    case "spinelim": spinnerSyncItems(); state.spinner.elim=!state.spinner.elim; render(); break;
    case "spinshuffle": { spinnerSyncItems(); const a=state.spinner.items.slice(); for(let k=a.length-1;k>0;k--){ const jj=Math.floor(Math.random()*(k+1)); const tm=a[k]; a[k]=a[jj]; a[jj]=tm; } state.spinner.items=a; try{ localStorage.setItem(STORE_KEY+"_spin", JSON.stringify(a)); }catch(e){} state.spinner.result=null; render(); break; }
    case "spinreset": state.spinner.items=t("spin_default").split("|"); state.spinner.result=null; state.spinAngle=0; try{ localStorage.removeItem(STORE_KEY+"_spin"); }catch(e){} render(); break;
    case "livefilterset": syncBuilder(); state.liveBuilder.filter=(v==="1"); render(); break;
    case "home": stopAll(); state.screen="home"; render(); break;
    case "library": state.screen="library"; render(); break;
    case "lang": state.lang=v; saveStore(); render(); break;
    case "sound": state.sound=!state.sound; saveStore(); render(); break;
    case "settings": state.modal="settings"; renderModal(); break;
    case "closemodal": case "closemodal-bg": state.modal=null; renderModal(); break;

    case "create": if(!requireAdmin())break; state.editing=Object.assign(blankQuiz(),{__new:true}); state.screen="editor"; render(); break;
    case "edit": {
      if(!requireAdmin())break;
      const q=state.quizzes.find(x=>x.id===id);
      if(q.sample){ const ed=toEditable(q); ed.id=uid(); ed.title=ed.title+" ✎"; ed.__new=true; state.editing=ed; }
      else { state.editing=toEditable(q); state.editing.__src=id; }
      state.screen="editor"; render(); break;
    }
    case "dup": {
      if(!requireAdmin())break;
      const q=state.quizzes.find(x=>x.id===id); const c=toEditable(q); c.id=uid(); c.sample=false;
      c.title=(L(q.title))+" ("+(state.lang==="ro"?"copie":"copy")+")";
      (async()=>{ try{ await Store.saveQuiz(c); await refreshQuizzes(); state.screen="library"; render(); toast(t("saved")); }catch(e){ toast(apiErr(e)); } })();
      break;
    }
    case "export": exportQuiz(state.quizzes.find(x=>x.id===id)); break;
    case "delquiz": {
      if(!requireAdmin())break;
      if(confirm(t("confirm_del"))){ (async()=>{ try{ await Store.deleteQuiz(id); await refreshQuizzes(); render(); toast(t("deleted")); }catch(e){ toast(apiErr(e)); } })(); }
      break;
    }
    case "import": state.screen="import"; render(); break;
    case "doimport": { const v2=(document.getElementById("pastein")||{}).value||""; if(v2.trim()) tryImport(v2); else toast(t("import_err")); break; }

    // editor
    case "addq": state.editing.questions.push(blankQuestion()); rerenderQList(); break;
    case "accadd": { const qi=+el.dataset.q; const qq=state.editing.questions[qi]; if(qq.answers.length<6){ qq.answers.push({text:"",correct:true}); rerenderQList(); } break; }
    case "accrm": { const qi=+el.dataset.q, ai=+el.dataset.a; const qq=state.editing.questions[qi]; if(qq.answers.length>1){ qq.answers.splice(ai,1); rerenderQList(); } break; }
    case "rmq": { const i=+el.dataset.q; if(state.editing.questions.length>1){ state.editing.questions.splice(i,1); rerenderQList(); } break; }
    case "qup": { const i=+el.dataset.q; if(i>0){ const arr=state.editing.questions; [arr[i-1],arr[i]]=[arr[i],arr[i-1]]; rerenderQList(); } break; }
    case "qdown": { const i=+el.dataset.q; const arr=state.editing.questions; if(i<arr.length-1){ [arr[i+1],arr[i]]=[arr[i],arr[i+1]]; rerenderQList(); } break; }
    case "addans": { const i=+el.dataset.q; const q=state.editing.questions[i]; if(q.answers.length<4){ q.answers.push({text:"",correct:false}); rerenderQList(); } break; }
    case "rmans": { const i=+el.dataset.q, j=+el.dataset.a; const q=state.editing.questions[i];
      if(q.answers.length>2){ const wasCorrect=q.answers[j].correct; q.answers.splice(j,1); if(wasCorrect) q.answers[0].correct=true; rerenderQList(); } break; }
    case "savequiz": saveQuizFromEditor(); break;

    // setup / play
    case "setup": openSetup(id); break;
    case "mode": state.setup.mode=v; if(v==="solo"&&state.setup.players.length>1) state.setup.players=[state.setup.players[0]||""]; if(v==="hotseat"&&state.setup.players.length<2) state.setup.players=[state.setup.players[0]||"",""]; render(); break;
    case "addplayer": if(state.setup.players.length<8){ state.setup.players.push(""); render(); } break;
    case "rmplayer": { const i=+el.dataset.i; state.setup.players.splice(i,1); render(); break; }
    case "shuftoggle": state.setup.shuffle=!state.setup.shuffle; render(); break;
    case "teamtoggle": state.setup.teamCount = state.setup.teamCount>0 ? 0 : 2; render(); break;
    case "teamcount": state.setup.teamCount = parseInt(el.dataset.n,10); render(); break;
    case "startgame": startGame(); break;
    case "beginturn": startTurn(); break;
    case "answer": pressAnswer(+el.dataset.i); break;
    case "typesubmit": { const inp=document.getElementById("type-input"); const v=(inp&&inp.value||"").trim(); if(!v){ toast(t("type_need_answer")); break; } submitTextAnswer(v); break; }
    case "continue": afterReveal(); break;
    case "playagain": replayGame(); break;
    case "gohome": stopAll(); state.screen="home"; render(); break;
    case "quitgame": if(confirm(t("quit_q"))){ stopAll(); state.screen="library"; render(); } break;

    // feedback / guestbook
    case "guestbook": openGuestbook(null); break;
    case "fbfromquiz": { const q=state.play?state.play.quiz:null; const ctx=q?{id:q.id,title:L(q.title)}:null; stopAll(); openGuestbook(ctx); break; }
    case "fbstar": { const ff=state.fbForm||(state.fbForm={rating:0,quizId:""}); ff.rating=+v; syncFbForm(); const cont=document.getElementById("fb-stars"); if(cont){ cont.querySelectorAll(".star").forEach((s,idx)=>s.classList.toggle("on",(idx+1)<=ff.rating)); } break; }
    case "fbsubmit": submitFeedback(); break;
    case "fbdelete": { const ts=el.dataset.ts; (async()=>{ try{ await Store.deleteFeedback(ts); state.feedback=state.feedback.filter(x=>String(x.ts)!==String(ts)); render(); }catch(e){ toast(apiErr(e)); } })(); break; }
    case "fbapprove": { const ts=el.dataset.ts; (async()=>{ try{ await Store.approveFeedback(ts); const it=state.feedback.find(x=>String(x.ts)===String(ts)); if(it)it.status="pub"; render(); }catch(e){ toast(apiErr(e)); } })(); break; }

    // admin
    case "openlogin": state.modal="login"; renderModal(); break;
    case "dologin": doLogin(); break;
    case "dosetup": doSetup(); break;
    case "dologout": doLogout(); break;

    // live audience mode
    case "live": if(!onlineOnly()) break; state.screen="livehub"; render(); break;
    case "joincode": { const v=(document.getElementById("lb-join")||{}).value||""; if(v.trim()) openJoin(v.trim()); else toast(t("live_need_code")); break; }
    case "livemode": syncBuilder(); state.liveMode=(v==="deck"?"deck":"single"); render(); break;
    case "deckadd": deckAdd(); break;
    case "deckrm": { syncBuilder(); const i=parseInt(el.dataset.i,10); state.deck.slides.splice(i,1); render(); break; }
    case "deckup": { syncBuilder(); const i=parseInt(el.dataset.i,10); if(i>0){ const a=state.deck.slides; const tm=a[i]; a[i]=a[i-1]; a[i-1]=tm; } render(); break; }
    case "deckdown": { syncBuilder(); const i=parseInt(el.dataset.i,10); const a=state.deck.slides; if(i<a.length-1){ const tm=a[i]; a[i]=a[i+1]; a[i+1]=tm; } render(); break; }
    case "decklaunch": syncBuilder(); createDeck(); break;
    case "livemodset": syncBuilder(); state.liveBuilder.mod=(v==="1"); render(); break;
    case "liveapprove": liveControl("approve", el.dataset.id); break;
    case "deckexport": deckExport(); break;
    case "deckimport": { const f=document.getElementById("deck-file"); if(f) f.click(); break; }
    case "deckprev": liveControl("prev"); break;
    case "decknext": liveControl("next"); break;
    case "livetype": syncBuilder(); state.liveBuilder.type=v; render(); break;
    case "liveaddopt": syncBuilder(); if(state.liveBuilder.options.length<10) state.liveBuilder.options.push(""); render(); break;
    case "livermopt": { syncBuilder(); const i=parseInt(el.dataset.i,10); if(state.liveBuilder.options.length>2) state.liveBuilder.options.splice(i,1); render(); break; }
    case "livestart": liveStart(); break;
    case "livestop": stopPoll(); state.live=null; state.liveRole=null; state.screen="livehub"; render(); break;
    case "liveopena": liveControl("open"); break;
    case "liveclosea": liveControl("close"); break;
    case "liveclear": if(confirm(t("live_clear_q"))) liveControl("clear"); break;
    case "liveenda": if(confirm(t("live_end_q"))) liveControl("delete_session"); break;
    case "livehide": liveControl("hide", el.dataset.id); break;
    case "liveshow": liveControl("show", el.dataset.id); break;
    case "livedel": liveControl("delete", el.dataset.id); break;
    case "livecopy": copyJoin(); break;
    case "liveshare": shareJoin(); break;
    case "livepng": exportCloudPNG(); break;
    case "livejson": exportLiveJSON(); break;
    case "joinsubmit": joinSubmit(); break;
    case "joinpoll": joinPollVote(el.dataset.opt); break;
    case "joinrate": joinRate(parseInt(el.dataset.v,10)); break;
    case "scaleset": scaleSet(parseInt(el.dataset.si,10), parseInt(el.dataset.v,10)); break;
    case "scalesubmit": scaleSubmit(); break;
    case "ptminus": ptStep(parseInt(el.dataset.oi,10),-1); break;
    case "ptplus": ptStep(parseInt(el.dataset.oi,10),1); break;
    case "ptsubmit": ptSubmit(); break;
    case "liveaddstmt": syncBuilder(); if(state.liveBuilder.statements.length<6) state.liveBuilder.statements.push(""); render(); break;
    case "livermstmt": { syncBuilder(); const i=parseInt(el.dataset.i,10); if(state.liveBuilder.statements.length>2) state.liveBuilder.statements.splice(i,1); render(); break; }
    case "rankup": rankMove(parseInt(el.dataset.pos,10),-1); break;
    case "rankdown": rankMove(parseInt(el.dataset.pos,10),1); break;
    case "ranksubmit": rankSubmit(); break;
    case "liveratscale": syncBuilder(); state.liveBuilder.scale=(parseInt(el.dataset.v,10)===10?10:5); render(); break;
    case "liveanswer": liveControl("answer", el.dataset.id); break;
    case "livestar": liveControl("star", el.dataset.id); break;
    case "joinvote": joinVote(el.dataset.id); break;
    case "gavatar": { if(state.join){ const n=document.getElementById("gj-name")||document.getElementById("aj-name"); if(n) state.join.name=n.value; state.join.avatar=parseInt(el.dataset.i,10)||0; render(); } break; }
    case "nickgen": { const id=el.dataset.t; const inp=document.getElementById(id); if(inp){ inp.value=randomNick(); inp.focus(); } break; }
    case "gteam": if(state.join){ state.join.team=parseInt(el.dataset.i,10); render(); } break;
    case "gjoin": gameJoinSubmit(); break;
    case "gans": gameAnswer(parseInt(el.dataset.i,10)); break;
    case "greact": sendReaction(parseInt(el.dataset.e,10)); break;
    case "gtype": { const ti=document.getElementById("gp-type"); const v=(ti&&ti.value||"").trim(); if(!v){ toast(t("type_need_answer")); break; } gameAnswerText(v); break; }
    case "gstart": gameControl("start"); break;
    case "greveal": gameControl("reveal"); break;
    case "gnext": gameControl("next"); break;
    case "grestart": gameControl("restart"); break;
    case "gkick": gameControl("kick", el.dataset.id); break;
    case "pwainstall": pwaInstall(); break;
    case "pwadismiss": _deferredInstall=null; updatePwaBar(); break;
    case "ajoin": assignJoinSubmit(); break;
    case "aans": assignAnswer(parseInt(el.dataset.i,10)); break;
    case "atype": { const ti=document.getElementById("ap-type"); const v=(ti&&ti.value||"").trim(); if(!v){ toast(t("type_need_answer")); break; } assignAnswerText(v); break; }
    case "anext": assignNext(); break;
    case "aopen": liveControl("open"); break;
    case "aclose": liveControl("close"); break;
    case "greport": fetchReport(); break;
    case "reportback": state.screen="livehost"; render(); break;
    case "report_csv": exportReportCSV(); break;
    case "report_json": exportReportJSON(); break;
    case "report_print": window.print(); break;
    case "joinleave": stopPoll(); state.join=null; state.liveRole=null; state.screen="home"; render(); break;
  }
});

function stopAll(){ stopTimer(); const p=state.play; if(p&&p._timer) p._timer(); if(confettiRAF){ cancelAnimationFrame(confettiRAF); const cv=document.getElementById("confetti"); if(cv){cv.getContext("2d").clearRect(0,0,cv.width,cv.height); cv.classList.add("hidden");} } document.onkeydown=null; state.play=null; }

function openSetup(id){
  const q=state.quizzes.find(x=>x.id===id);
  state.setup={ quiz:q, mode:"solo", players:[""], shuffle:false, teamCount:0 };
  state.screen="setup"; render();
}

function replayGame(){
  const p=state.play; const quiz=p.quiz; const mode=p.mode; const names=p.participants.map(x=>x.name);
  stopAll();
  state.setup={quiz, mode, players:names, shuffle:false, teamCount:0};
  startGame();
}

async function saveQuizFromEditor(){
  if(!requireAdmin()) return;
  const q=state.editing;
  if(!q.title.trim()){ toast(t("err_title")); return; }
  if(!q.questions.length){ toast(t("err_noq")); return; }
  for(let i=0;i<q.questions.length;i++){
    const qq=q.questions[i];
    if(!String(qq.text).trim()){ toast(t("err_qtext",i+1)); return; }
    const valid=qq.answers.filter(a=>String(a.text).trim()).length;
    if(qq.type==="tf"){ } else if(qq.type==="type"){ if(valid<1){ toast(t("err_acc",i+1)); return; } } else if(qq.type==="num"){ const tgt=String(qq.answers[0]&&qq.answers[0].text||"").replace(",",".").trim(); if(tgt===""||!isFinite(parseFloat(tgt))){ toast(t("err_num",i+1)); return; } } else if(valid<2){ toast(t("err_ans",i+1)); return; }
    if(qq.type!=="type" && qq.type!=="num" && !qq.answers.some(a=>a.correct)){ toast(t("err_corr",i+1)); return; }
  }
  const clean={ id:q.__src||q.id, color:q.color||AV_COLORS[1], title:q.title.trim(), desc:(q.desc||"").trim(),
    questions:q.questions.map(qq=>({
      text:qq.text.trim(), time:qq.time||20, points:qq.points||1000, type:qq.type||"quiz",
      answers:(qq.type==="tf"
        ? [{text:t("tf_true"),correct:!!qq.answers[0].correct},{text:t("tf_false"),correct:!!qq.answers[1].correct}]
        : qq.type==="type"
        ? qq.answers.filter(a=>String(a.text).trim()).map(a=>({text:a.text.trim(),correct:true}))
        : qq.type==="num"
        ? [{text:String(qq.answers[0]&&qq.answers[0].text||"").trim(),correct:true}]
        : qq.answers.filter(a=>String(a.text).trim()).map(a=>({text:a.text.trim(),correct:!!a.correct}))),
      tol:(qq.type==="num"?Math.abs(parseFloat(String(qq.tol||"0").replace(",","."))||0):0)
    }))
  };
  try{
    await Store.saveQuiz(clean);
    await refreshQuizzes();
    toast(t("saved"));
    state.editing=null; state.screen="library"; render();
  }catch(e){ toast(apiErr(e)); }
}

/* ---------------- init ---------------- */
state.loading = state.server;
const _joinCode=(new URLSearchParams(location.search)).get("join");
render();
initStore().then(()=>{ state.loading=false; if(_joinCode){ openJoin(_joinCode); } else { render(); } }).catch(()=>{ state.loading=false; if(_joinCode){ openJoin(_joinCode); } else { render(); } });
initPWA();
window.addEventListener("resize",()=>{ const cv=document.getElementById("confetti"); if(cv&&!cv.classList.contains("hidden")){ cv.width=innerWidth*Math.min(devicePixelRatio||1,2); cv.height=innerHeight*Math.min(devicePixelRatio||1,2);} });
</script>
</body>
</html>
