<?php
define('BBK_INSTALLER', 1);
foreach (['BackupException','Crypto','ArchiveWriter','ArchiveReader','PathGuard','FileScanner','SearchReplace','FileArchiver','Restorer','Db','DbExporter','DbImporter'] as $c) require __DIR__."/../core/$c.php";
use BastionBackup\Core\{ArchiveWriter,ArchiveReader,FileScanner,FileArchiver,Restorer,SearchReplace,BackupException};

$base = sys_get_temp_dir().'/bbk'.bin2hex(random_bytes(3)); mkdir("$base/src/wp-content/uploads/2026",0777,true); mkdir("$base/src/empty",0777,true);
file_put_contents("$base/src/index.php", "<?php echo 1;");
file_put_contents("$base/src/wp-content/uploads/2026/big.bin", random_bytes(7*1048576+123));
file_put_contents("$base/src/wp-content/text.txt", str_repeat("hello world ", 500000));
file_put_contents("$base/src/wp-content/uploads/skip.log", "x");
symlink('/etc/passwd', "$base/src/evil-link");
$pass = 'correct horse battery staple';
$arch = "$base/a.bbk";

// --- backup with tiny deadlines to force mid-file resume
$w = ArchiveWriter::create($arch, $pass, 'test'); $w->record('S',['site_url'=>'http://old.test'],0);
$st = $w->export(); $w->close();
$sc = new FileScanner([''=>"$base/src"], ['*.log']); $ss = $sc->initState();
while(!$sc->step($ss, "$base/list", microtime(true)+0.001));
$fa = new FileArchiver([''=>"$base/src"]); $fs = FileArchiver::initState(); $steps=0;
do { $w = ArchiveWriter::resume($arch, $st); $done = $fa->step($fs, "$base/list", $w, microtime(true)+0.02); $st = $w->export(); $w->close(); $steps++; } while(!$done);
$w = ArchiveWriter::resume($arch, $st); $w->record('X',['files'=>$fs['files']],0); $w->finish();
echo "scan files={$ss['files']} skipped={$ss['skipped']} archived={$fs['files']} steps=$steps size=".filesize($arch)."\n";

// --- verify + restore with resume
$rs = Restorer::initState(); $n=0;
$mapper = fn($p) => ['root'=>"$base/dst",'rel'=>$p];
mkdir("$base/dst");
$r = new Restorer($arch, $pass, 'restore', null, $mapper, false, true);
while(!$r->step($rs, microtime(true)+0.02)) $n++;
echo "restore steps=$n files={$rs['files']} manifest=".json_encode($rs['manifest'])." index=".json_encode($rs['index'])."\n";
foreach (['index.php','wp-content/uploads/2026/big.bin','wp-content/text.txt'] as $f) echo $f, ': ', md5_file("$base/src/$f")===md5_file("$base/dst/$f")?'OK':'MISMATCH', "\n";
echo 'empty dir: ', is_dir("$base/dst/empty")?'OK':'MISSING', "\n";
echo 'symlink excluded: ', file_exists("$base/dst/evil-link")?'FAIL':'OK', "\n";
echo 'log excluded: ', file_exists("$base/dst/wp-content/uploads/skip.log")?'FAIL':'OK', "\n";

// --- wrong passphrase
try { Restorer::manifest($arch, 'wrong passphrase!!'); echo "wrongpass: FAIL\n"; } catch (BackupException $e) { echo "wrongpass: OK ({$e->getMessage()})\n"; }
// --- tamper
$t = "$base/t.bbk"; copy($arch,$t); $fh=fopen($t,'r+b'); fseek($fh, filesize($t)-5000); fwrite($fh,"\xff"); fclose($fh);
try { $s=Restorer::initState(); $v=new Restorer($t,$pass); while(!$v->step($s, microtime(true)+5)); echo "tamper: FAIL\n"; } catch (BackupException $e) { echo "tamper: OK ({$e->getMessage()})\n"; }
// --- truncate
copy($arch,$t); $fh=fopen($t,'r+b'); ftruncate($fh, filesize($arch)-100); fclose($fh);
try { $s=Restorer::initState(); $v=new Restorer($t,$pass); while(!$v->step($s, microtime(true)+5)); echo "truncate: FAIL\n"; } catch (BackupException $e) { echo "truncate: OK ({$e->getMessage()})\n"; }
// --- traversal
$ev="$base/evil.bbk"; $w=ArchiveWriter::create($ev,$pass); $w->record('F',['p'=>'../../etc/x'],1); $w->write('a'); $w->finish();
try { $s=Restorer::initState(); $v=new Restorer($ev,$pass,'restore',null,$mapper,false,true); while(!$v->step($s, microtime(true)+5)); echo "traversal: FAIL\n"; } catch (BackupException $e) { echo "traversal: OK ({$e->getMessage()})\n"; }

// --- search replace
$pairs = SearchReplace::expand(['http://old.test'=>'https://new.example.com', '/var/www/old'=>'/srv/new']);
$o = new stdClass; $o->u='http://old.test/x';
$val = serialize(['url'=>'http://old.test/page','nested'=>serialize(['p'=>'/var/www/old/wp']), 'obj'=>$o, 'n'=>5]);
$out = SearchReplace::run($val, $pairs); $d = unserialize($out, ['allowed_classes'=>['stdClass']]);
echo 'serialized: ', ($d['url']==='https://new.example.com/page' && unserialize($d['nested'])['p']==='/srv/new/wp' && $d['obj']->u==='https://new.example.com/x')?'OK':'FAIL', "\n";
echo 'json: ', SearchReplace::run('{"u":"http:\/\/old.test\/a"}', $pairs)==='{"u":"https:\/\/new.example.com\/a"}'?'OK':'FAIL', "\n";
class Evil { function __destruct(){ echo "INJECTED\n"; } }
$ev = serialize(['a'=>new Evil, 'u'=>'http://old.test']); echo 'object-injection: ', SearchReplace::run($ev,$pairs)===$ev?'OK (left untouched)':'changed', "\n";
exec("rm -rf ".escapeshellarg($base));
