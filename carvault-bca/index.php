<?php
session_start();
mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('MYSQLHOST') ?: 'localhost';
$port = (int)(getenv('MYSQLPORT') ?: 3306);
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: '';
$db   = getenv('MYSQLDATABASE') ?: 'railway';

$conn = @new mysqli($host, $user, $pass, $db, $port);
if ($conn->connect_errno) {
    http_response_code(500);
    die('<!doctype html><html><body style="font-family:Arial;background:#0b0e13;color:white;padding:60px"><h2>CarVault is temporarily unavailable.</h2><p>Please refresh in a moment.</p></body></html>');
}
$conn->set_charset('utf8mb4');

$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS cars (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    car_name VARCHAR(120) NOT NULL,
    brand VARCHAR(100) NOT NULL,
    model VARCHAR(120) NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    color VARCHAR(60) NOT NULL,
    fuel_type ENUM('Petrol','Diesel','Electric','Hybrid','CNG','Other') NOT NULL DEFAULT 'Petrol',
    price DECIMAL(14,2) NOT NULL DEFAULT 0,
    image_data MEDIUMTEXT NULL,
    image_type VARCHAR(50) NULL,
    image_url TEXT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX(user_id), INDEX(brand), INDEX(year),
    CONSTRAINT fk_cars_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@$conn->query("ALTER TABLE cars ADD COLUMN image_url TEXT NULL AFTER image_type");

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function go($u){ header('Location: '.$u); exit; }
function logged(){ return !empty($_SESSION['user_id']); }
function guard(){ if(!logged()) go('?p=login'); }
function flash($t,$m){ $_SESSION['flash']=[$t,$m]; }
function csrf(){ return isset($_POST['csrf'],$_SESSION['csrf']) && hash_equals($_SESSION['csrf'],$_POST['csrf']); }
function oneCar($conn,$id,$uid){
    $s=$conn->prepare('SELECT * FROM cars WHERE id=? AND user_id=? LIMIT 1');
    $s->bind_param('ii',$id,$uid); $s->execute();
    return $s->get_result()->fetch_assoc();
}
function fallbackImage($brand=''){
    $b=strtolower($brand);
    if(str_contains($b,'ferrari')) return 'https://images.unsplash.com/photo-1592198084033-aade902d1aae?auto=format&fit=crop&w=1600&q=86';
    if(str_contains($b,'bmw')) return 'https://images.unsplash.com/photo-1670727229851-79aee0a1d1a9?auto=format&fit=crop&w=1600&q=86';
    if(str_contains($b,'tesla')) return 'https://images.unsplash.com/photo-1606016159991-dfe4f2746ad5?auto=format&fit=crop&w=1600&q=86';
    if(str_contains($b,'toyota')) return 'https://images.unsplash.com/photo-1666739339626-0b4cdec04a24?auto=format&fit=crop&w=1600&q=86';
    return 'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=1600&q=86';
}
function imageSrc($c){
    if(!empty($c['image_data']) && !empty($c['image_type'])) return 'data:'.e($c['image_type']).';base64,'.$c['image_data'];
    if(!empty($c['image_url'])) return e($c['image_url']);
    return fallbackImage($c['brand'] ?? '');
}
function inr($n){ return '₹'.number_format((float)$n,0); }

$p = $_GET['p'] ?? 'home';
$errors=[];

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf()){ flash('error','Your session expired. Please try again.'); go('?p='.$p); }
    $a=$_POST['action']??'';

    if($a==='register'){
        $name=trim($_POST['name']??''); $email=trim($_POST['email']??'');
        $pw=$_POST['password']??''; $cp=$_POST['confirm']??'';
        if(strlen($name)<2) $errors[]='Enter your full name.';
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) $errors[]='Enter a valid email address.';
        if(strlen($pw)<6) $errors[]='Password must be at least 6 characters.';
        if($pw!==$cp) $errors[]='Passwords do not match.';
        if(!$errors){
            $hash=password_hash($pw,PASSWORD_DEFAULT);
            $s=$conn->prepare('INSERT INTO users(name,email,password) VALUES(?,?,?)');
            $s->bind_param('sss',$name,$email,$hash);
            if($s->execute()){ flash('success','Your CarVault account is ready. Sign in to continue.'); go('?p=login'); }
            $errors[]=$s->errno===1062?'This email is already registered.':'Could not create your account.';
        }
    }

    if($a==='login'){
        $email=trim($_POST['email']??''); $pw=$_POST['password']??'';
        $s=$conn->prepare('SELECT id,name,email,password FROM users WHERE email=? LIMIT 1');
        $s->bind_param('s',$email); $s->execute(); $u=$s->get_result()->fetch_assoc();
        if($u && password_verify($pw,$u['password'])){
            session_regenerate_id(true);
            $_SESSION['user_id']=$u['id']; $_SESSION['user_name']=$u['name']; $_SESSION['user_email']=$u['email'];
            flash('success','Welcome back, '.$u['name'].'.'); go('?p=dashboard');
        }
        $errors[]='Invalid email or password.';
    }

    if($a==='logout'){
        session_unset(); session_destroy(); go('?');
    }

    if(in_array($a,['save_car','delete_car'],true)) guard();

    if($a==='delete_car'){
        $id=(int)($_POST['id']??0); $uid=(int)$_SESSION['user_id'];
        $s=$conn->prepare('DELETE FROM cars WHERE id=? AND user_id=?');
        $s->bind_param('ii',$id,$uid); $s->execute();
        flash('success','Vehicle removed from your collection.'); go('?p=collection');
    }

    if($a==='save_car'){
        $uid=(int)$_SESSION['user_id']; $id=(int)($_POST['id']??0);
        $name=trim($_POST['car_name']??''); $brand=trim($_POST['brand']??''); $model=trim($_POST['model']??'');
        $year=(int)($_POST['year']??0); $color=trim($_POST['color']??''); $fuel=$_POST['fuel_type']??'Petrol';
        $price=(float)($_POST['price']??0); $desc=trim($_POST['description']??'');
        $fuels=['Petrol','Diesel','Electric','Hybrid','CNG','Other'];
        if(!$name) $errors[]='Car name is required.';
        if(!$brand) $errors[]='Brand is required.';
        if(!$model) $errors[]='Model is required.';
        if($year<1900 || $year>(int)date('Y')+1) $errors[]='Enter a valid year.';
        if(!$color) $errors[]='Colour is required.';
        if(!in_array($fuel,$fuels,true)) $errors[]='Invalid fuel type.';
        if($price<0) $errors[]='Enter a valid price.';

        $imgData=null; $imgType=null;
        if(isset($_FILES['image']) && $_FILES['image']['error']!==UPLOAD_ERR_NO_FILE){
            if($_FILES['image']['error']!==UPLOAD_ERR_OK) $errors[]='Image upload failed.';
            elseif($_FILES['image']['size']>1500000) $errors[]='Image must be smaller than 1.5MB.';
            else{
                $mime=mime_content_type($_FILES['image']['tmp_name']);
                if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)) $errors[]='Only JPG, PNG and WEBP images are allowed.';
                else { $imgData=base64_encode(file_get_contents($_FILES['image']['tmp_name'])); $imgType=$mime; }
            }
        }

        if(!$errors){
            if($id){
                $old=oneCar($conn,$id,$uid);
                if(!$old){ flash('error','Vehicle not found.'); go('?p=collection'); }
                if($imgData!==null){
                    $s=$conn->prepare('UPDATE cars SET car_name=?,brand=?,model=?,year=?,color=?,fuel_type=?,price=?,image_data=?,image_type=?,description=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?');
                    $s->bind_param('sssissdsssii',$name,$brand,$model,$year,$color,$fuel,$price,$imgData,$imgType,$desc,$id,$uid);
                }else{
                    $s=$conn->prepare('UPDATE cars SET car_name=?,brand=?,model=?,year=?,color=?,fuel_type=?,price=?,description=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?');
                    $s->bind_param('sssissdsii',$name,$brand,$model,$year,$color,$fuel,$price,$desc,$id,$uid);
                }
                if($s->execute()){ flash('success','Vehicle details updated.'); go('?p=view&id='.$id); }
                $errors[]='Could not update the vehicle.';
            }else{
                $s=$conn->prepare('INSERT INTO cars(user_id,car_name,brand,model,year,color,fuel_type,price,image_data,image_type,description) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
                $s->bind_param('isssissdsss',$uid,$name,$brand,$model,$year,$color,$fuel,$price,$imgData,$imgType,$desc);
                if($s->execute()){ flash('success','Vehicle added to your collection.'); go('?p=collection'); }
                $errors[]='Could not save the vehicle.';
            }
        }
    }
}

if(in_array($p,['dashboard','collection','add','edit','view'],true)) guard();
$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
$uid=(int)($_SESSION['user_id']??0);
$editCar=null;
if(in_array($p,['edit','view'],true)){
    $editCar=oneCar($conn,(int)($_GET['id']??0),$uid);
    if(!$editCar){ flash('error','Vehicle not found.'); go('?p=collection'); }
}

$heroImage='https://images.unsplash.com/photo-1592198084033-aade902d1aae?auto=format&fit=crop&w=1800&q=90';
$authImage='https://images.unsplash.com/photo-1670727229851-79aee0a1d1a9?auto=format&fit=crop&w=1800&q=88';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#090b10">
<title>CarVault — Your Private Garage</title>
<style>
:root{--ink:#0b0d12;--ink2:#171a21;--paper:#f6f7f9;--card:#fff;--text:#111827;--muted:#6b7280;--line:#e7e9ee;--accent:#315efb;--accent2:#2448c9;--green:#0d9f6e;--danger:#d92d20;--shadow:0 18px 60px rgba(13,18,31,.09);--shadow2:0 10px 30px rgba(13,18,31,.08);--r:22px}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:var(--paper);color:var(--text);line-height:1.55;-webkit-font-smoothing:antialiased}a{text-decoration:none;color:inherit}img{display:block;max-width:100%}button,input,select,textarea{font:inherit}.container{width:min(1180px,calc(100% - 32px));margin:auto}.top{position:sticky;top:0;z-index:50;background:rgba(9,11,16,.94);backdrop-filter:blur(18px);border-bottom:1px solid rgba(255,255,255,.08)}.nav{min-height:74px;display:flex;align-items:center;gap:22px}.brand{display:flex;align-items:center;gap:11px;margin-right:auto;color:#fff;font-weight:900;font-size:20px;letter-spacing:-.045em}.mark{width:39px;height:39px;border-radius:12px;background:#fff;display:grid;place-items:center;box-shadow:0 8px 24px rgba(0,0,0,.2)}.mark svg{width:24px;height:24px}.navlink{color:#c8ced9;font-size:14px;font-weight:750;padding:9px 4px}.navlink:hover{color:#fff}.btn{appearance:none;border:0;border-radius:12px;padding:11px 17px;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:.18s ease}.btn:hover{transform:translateY(-1px)}.btn.primary{background:var(--accent);color:#fff;box-shadow:0 8px 22px rgba(49,94,251,.25)}.btn.primary:hover{background:var(--accent2)}.btn.light{background:#fff;color:#10131a;border:1px solid #e5e7eb}.btn.dark{background:#11141b;color:#fff}.btn.danger{background:#feeceb;color:#b42318}.btn.navout{background:rgba(255,255,255,.08);color:#fff;border:1px solid rgba(255,255,255,.12)}
.hero{background:#090b10;color:#fff;padding:72px 0 70px;overflow:hidden;position:relative}.hero:before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 14% 20%,rgba(49,94,251,.24),transparent 29%),radial-gradient(circle at 82% 34%,rgba(124,58,237,.13),transparent 30%);pointer-events:none}.heroGrid{position:relative;display:grid;grid-template-columns:.94fr 1.06fr;gap:54px;align-items:center}.eyebrow{display:inline-flex;align-items:center;gap:8px;text-transform:uppercase;letter-spacing:.15em;font-size:11px;font-weight:900;color:#637efc}.hero .eyebrow{color:#9db1ff}.hero h1{font-size:clamp(46px,6.8vw,82px);line-height:.94;letter-spacing:-.072em;margin:16px 0 22px;max-width:720px}.hero h1 span{color:#9fb1ff}.lead{font-size:18px;color:#aeb6c5;max-width:610px;margin:0}.heroActions{display:flex;gap:11px;flex-wrap:wrap;margin-top:30px}.hero .btn.light{background:#fff;color:#0d1016;border:0}.heroVisual{position:relative;border-radius:30px;overflow:hidden;min-height:510px;box-shadow:0 34px 90px rgba(0,0,0,.45);border:1px solid rgba(255,255,255,.12)}.heroVisual img{width:100%;height:510px;object-fit:cover}.heroVisual:after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,transparent 42%,rgba(5,7,10,.84) 100%)}.heroBadge{position:absolute;z-index:2;left:22px;top:22px;padding:9px 12px;border-radius:999px;background:rgba(9,11,16,.72);backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,.18);font-size:12px;font-weight:800}.heroInfo{position:absolute;z-index:2;left:26px;right:26px;bottom:24px;display:flex;justify-content:space-between;align-items:end;gap:20px}.heroInfo strong{font-size:28px;letter-spacing:-.04em}.heroInfo small{display:block;color:#c1c8d4}.metricStrip{background:#11141b;border-top:1px solid rgba(255,255,255,.08);border-bottom:1px solid rgba(255,255,255,.08)}.metricGrid{display:grid;grid-template-columns:repeat(4,1fr)}.metric{padding:22px 24px;color:#fff;border-right:1px solid rgba(255,255,255,.08)}.metric:last-child{border-right:0}.metric strong{display:block;font-size:20px}.metric span{font-size:12px;color:#98a2b3}
.section{padding:78px 0}.section.white{background:#fff}.sectionHead{display:flex;justify-content:space-between;align-items:end;gap:30px;margin-bottom:34px}.sectionHead h2{font-size:clamp(32px,4vw,50px);letter-spacing:-.055em;line-height:1.02;margin:10px 0 0}.sectionHead p{max-width:520px;color:var(--muted);margin:0}.featureGrid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.feature{background:#fff;border:1px solid var(--line);border-radius:20px;padding:24px;min-height:210px;box-shadow:0 10px 30px rgba(13,18,31,.035)}.featureIcon{width:44px;height:44px;border-radius:13px;background:#eef2ff;color:#315efb;display:grid;place-items:center;font-weight:900;margin-bottom:28px}.feature h3{font-size:20px;letter-spacing:-.03em;margin:0 0 8px}.feature p{color:var(--muted);font-size:14px;margin:0}.showcase{display:grid;grid-template-columns:1.15fr .85fr;gap:18px}.showcaseMain,.showcaseSide{border-radius:26px;overflow:hidden;position:relative;min-height:420px;background:#111}.showcaseMain img,.showcaseSide img{width:100%;height:100%;position:absolute;inset:0;object-fit:cover}.showcaseMain:after,.showcaseSide:after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.05),rgba(0,0,0,.78))}.showcaseCopy{position:absolute;z-index:2;left:26px;right:26px;bottom:24px;color:white}.showcaseCopy h3{font-size:31px;letter-spacing:-.045em;margin:6px 0}.showcaseCopy p{color:#d3d8e2;margin:0;max-width:500px}
.page{padding:46px 0 76px}.pageHead{display:flex;align-items:end;justify-content:space-between;gap:24px;margin-bottom:26px}.pageHead h1{font-size:clamp(36px,5vw,54px);line-height:1;letter-spacing:-.06em;margin:8px 0 6px}.sub{color:var(--muted);margin:0}.panel,.stat,.vehicleCard,.authCard,.detailPanel{background:#fff;border:1px solid var(--line);border-radius:var(--r);box-shadow:0 10px 32px rgba(13,18,31,.045)}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}.stat{padding:20px}.statTop{display:flex;justify-content:space-between;align-items:center;color:var(--muted);font-size:12px;font-weight:750}.stat strong{display:block;font-size:28px;letter-spacing:-.045em;margin-top:9px}.stat small{color:#98a2b3}.dashGrid{display:grid;grid-template-columns:1.25fr .75fr;gap:18px}.panel{padding:22px}.panelTitle{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:16px}.panelTitle h2{margin:0;font-size:23px;letter-spacing:-.035em}.featuredVehicle{position:relative;overflow:hidden;border-radius:18px;min-height:390px;background:#111}.featuredVehicle img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.featuredVehicle:after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,transparent 35%,rgba(5,7,10,.88))}.featuredCopy{position:absolute;z-index:2;left:22px;right:22px;bottom:20px;color:white}.featuredCopy h3{font-size:28px;letter-spacing:-.04em;margin:5px 0}.featuredCopy p{color:#ced3dd;margin:0}.fuelRows{display:grid;gap:13px}.fuelRowTop{display:flex;justify-content:space-between;font-size:13px;font-weight:750}.bar{height:8px;background:#eef1f5;border-radius:999px;overflow:hidden;margin-top:7px}.bar span{height:100%;display:block;background:linear-gradient(90deg,#315efb,#7c5cff);border-radius:999px}.miniCars{display:grid;gap:10px}.miniCar{display:grid;grid-template-columns:82px 1fr auto;gap:12px;align-items:center;padding:8px;border:1px solid var(--line);border-radius:13px}.miniCar img{width:82px;height:64px;object-fit:cover;border-radius:10px}.miniCar h4{margin:0;font-size:14px}.miniCar small{color:var(--muted)}
.filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:10px;margin-bottom:18px;background:#fff;border:1px solid var(--line);padding:13px;border-radius:16px}.filters input,.filters select,.field input,.field select,.field textarea{width:100%;border:1px solid #d8dce4;background:#fff;border-radius:11px;padding:12px 13px;outline:none;color:#111827}.filters input:focus,.filters select:focus,.field input:focus,.field select:focus,.field textarea:focus{border-color:#7a93f5;box-shadow:0 0 0 4px rgba(49,94,251,.09)}.resultLine{display:flex;justify-content:space-between;align-items:center;color:var(--muted);font-size:13px;margin:0 2px 15px}.cars{display:grid;grid-template-columns:repeat(3,1fr);gap:17px}.vehicleCard{overflow:hidden;transition:.2s ease}.vehicleCard:hover{transform:translateY(-4px);box-shadow:0 18px 46px rgba(13,18,31,.10)}.vehicleImage{position:relative;aspect-ratio:16/10;overflow:hidden;background:#e9edf3}.vehicleImage img{width:100%;height:100%;object-fit:cover;transition:.35s ease}.vehicleCard:hover .vehicleImage img{transform:scale(1.025)}.yearPill{position:absolute;top:13px;right:13px;background:rgba(10,12,17,.78);color:#fff;backdrop-filter:blur(10px);font-size:11px;font-weight:900;border-radius:999px;padding:6px 9px}.vehicleBody{padding:17px}.brandLine{font-size:11px;color:#7c8492;text-transform:uppercase;letter-spacing:.12em;font-weight:900}.vehicleBody h3{font-size:20px;letter-spacing:-.04em;margin:5px 0 8px}.metaChips{display:flex;gap:7px;flex-wrap:wrap;margin:12px 0}.chip{font-size:11px;color:#5e6674;border:1px solid var(--line);background:#fafbfc;padding:5px 8px;border-radius:8px}.price{font-size:19px;font-weight:900;letter-spacing:-.025em}.cardActions{display:flex;gap:8px;border-top:1px solid var(--line);margin-top:15px;padding-top:13px}.cardActions a,.cardActions button{font-size:12px;font-weight:850;border:0;border-radius:9px;padding:8px 10px;cursor:pointer;background:#eef2ff;color:#315efb}.cardActions .edit{background:#f4f1ff;color:#6d38d1}.cardActions .del{margin-left:auto;background:#fff0ef;color:#b42318}.empty{background:#fff;border:1px dashed #cfd4dd;border-radius:22px;padding:55px 24px;text-align:center}.empty h3{font-size:24px;margin:0 0 7px}.empty p{color:var(--muted);margin:0 0 20px}
.formWrap{max-width:940px}.formPanel{background:#fff;border:1px solid var(--line);border-radius:24px;padding:26px;box-shadow:var(--shadow2)}.formPanel h2{font-size:22px;letter-spacing:-.035em;margin:0 0 20px}.formGrid{display:grid;grid-template-columns:1fr 1fr;gap:17px}.field{display:grid;gap:7px;color:#374151;font-size:12px;font-weight:850}.field.full{grid-column:1/-1}.field textarea{resize:vertical;min-height:120px}.field small{color:#9aa1ad;font-weight:550}.uploadPreview{grid-column:1/-1;background:#f8f9fb;border:1px dashed #cfd5df;border-radius:16px;padding:14px;display:flex;align-items:center;gap:14px}.previewBox{width:112px;height:76px;border-radius:12px;background:#e8ecf2;overflow:hidden;display:grid;place-items:center;color:#8b94a4}.previewBox img{width:100%;height:100%;object-fit:cover}.formActions{display:flex;gap:10px;margin-top:20px}
.authPage{min-height:calc(100vh - 74px);display:grid;grid-template-columns:1.05fr .95fr;background:#0a0c11}.authVisual{position:relative;overflow:hidden;min-height:700px}.authVisual img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.authVisual:after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(5,6,9,.05),rgba(5,6,9,.82))}.authCopy{position:absolute;z-index:2;left:7%;right:7%;bottom:7%;color:#fff}.authCopy h2{font-size:42px;letter-spacing:-.055em;line-height:1;margin:10px 0}.authCopy p{color:#d5d9e1;max-width:520px}.authSide{display:grid;place-items:center;padding:46px 24px;background:#f6f7f9}.authCard{width:min(480px,100%);padding:31px}.authCard h1{font-size:36px;letter-spacing:-.05em;margin:9px 0 8px}.authCard p{color:var(--muted)}.authCard form{display:grid;gap:14px;margin-top:24px}.authFoot{text-align:center;color:var(--muted);font-size:13px;margin-top:18px}.authFoot a{color:#315efb;font-weight:850}.alert{width:min(1180px,calc(100% - 32px));margin:14px auto 0;padding:13px 15px;border-radius:12px;font-weight:750;font-size:13px}.alert.success{background:#ecfdf3;border:1px solid #abefc6;color:#067647}.alert.error{background:#fef3f2;border:1px solid #fecdca;color:#b42318}
.detail{display:grid;grid-template-columns:1.12fr .88fr;gap:18px}.detailImage{position:relative;overflow:hidden;border-radius:24px;min-height:560px;background:#111;box-shadow:var(--shadow2)}.detailImage img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.detailImage:after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,transparent 58%,rgba(0,0,0,.45))}.detailPanel{padding:28px}.detailPanel h1{font-size:44px;letter-spacing:-.055em;line-height:1;margin:9px 0 7px}.detailPrice{font-size:31px;font-weight:900;letter-spacing:-.04em;margin:24px 0}.specs{display:grid;grid-template-columns:1fr 1fr;gap:10px}.spec{border:1px solid var(--line);background:#fafbfc;border-radius:13px;padding:13px}.spec span{display:block;color:#8b94a4;font-size:10px;text-transform:uppercase;letter-spacing:.12em;font-weight:900}.spec strong{font-size:14px}.notes{margin-top:14px;padding:16px;border-radius:14px;background:#f8f9fb;color:#4b5563}.notes b{display:block;color:#111827;margin-bottom:4px}.detailActions{display:flex;gap:9px;margin-top:20px}.back{display:inline-flex;align-items:center;gap:6px;color:#315efb;font-weight:850;font-size:13px;margin-bottom:16px}
.footer{background:#090b10;color:#98a2b3;border-top:1px solid rgba(255,255,255,.08);padding:32px 0}.footerInner{display:flex;justify-content:space-between;gap:20px;align-items:center}.footer .brand{margin:0;font-size:17px}.footer small{color:#777f8e}
@media(max-width:980px){.heroGrid,.showcase,.dashGrid,.detail,.authPage{grid-template-columns:1fr}.authVisual{min-height:430px}.featureGrid,.cars{grid-template-columns:repeat(2,1fr)}.stats{grid-template-columns:repeat(2,1fr)}.filters{grid-template-columns:1fr 1fr}.filters .wide{grid-column:1/-1}.heroVisual{min-height:430px}.heroVisual img{height:430px}}
@media(max-width:680px){.navlink{display:none}.nav{gap:9px}.brand{font-size:18px}.hero{padding:48px 0}.hero h1{font-size:50px}.heroVisual{min-height:340px}.heroVisual img{height:340px}.metricGrid,.featureGrid,.cars,.stats,.formGrid,.specs,.filters{grid-template-columns:1fr}.filters .wide,.field.full{grid-column:auto}.metric{border-right:0;border-bottom:1px solid rgba(255,255,255,.08)}.section{padding:58px 0}.sectionHead,.pageHead,.footerInner{align-items:flex-start;flex-direction:column}.showcaseMain,.showcaseSide{min-height:350px}.pageHead h1{font-size:40px}.authVisual{min-height:330px}.authCopy h2{font-size:32px}.authSide{padding:30px 16px}.authCard{padding:24px}.detailImage{min-height:380px}.detailPanel h1{font-size:38px}.formPanel{padding:20px}.formActions,.detailActions{flex-direction:column}.formActions .btn,.detailActions .btn{width:100%}.uploadPreview{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<header class="top"><div class="container nav">
<a class="brand" href="?"><span class="mark"><svg viewBox="0 0 24 24" fill="none" stroke="#0b0d12" stroke-width="1.8"><path d="M4 15.5h16l-1.6-4.1a2.5 2.5 0 0 0-2.3-1.6H7.9a2.5 2.5 0 0 0-2.3 1.6L4 15.5Z"/><path d="M7 9.8 8.5 6h7L17 9.8M6 15.5v2M18 15.5v2"/><circle cx="7.5" cy="13.4" r=".8" fill="#0b0d12"/><circle cx="16.5" cy="13.4" r=".8" fill="#0b0d12"/></svg></span>CarVault</a>
<?php if(logged()): ?>
<a class="navlink" href="?p=dashboard">Dashboard</a><a class="navlink" href="?p=collection">Collection</a><a class="navlink" href="?p=add">Add vehicle</a>
<form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="logout"><button class="btn navout">Logout</button></form>
<?php else: ?>
<a class="navlink" href="?p=login">Sign in</a><a class="btn primary" href="?p=register">Create account</a>
<?php endif; ?>
</div></header>

<?php if($flash): ?><div class="alert <?=e($flash[0])?>"><?=e($flash[1])?></div><?php endif; ?>

<main>
<?php if($p==='home'): ?>
<section class="hero"><div class="container heroGrid">
<div><div class="eyebrow">Private garage management</div><h1>Your collection deserves more than a <span>spreadsheet.</span></h1><p class="lead">CarVault gives enthusiasts one beautifully organised place to store vehicle details, photos, values and the story behind every car they own.</p><div class="heroActions"><?php if(logged()): ?><a class="btn primary" href="?p=dashboard">Open your garage</a><?php else: ?><a class="btn primary" href="?p=register">Build your garage</a><a class="btn light" href="?p=login">Sign in</a><?php endif; ?></div></div>
<div class="heroVisual"><img src="<?=e($heroImage)?>" alt="Red performance car"><div class="heroBadge">Curated. Private. Always available.</div><div class="heroInfo"><div><small>Your garage, properly organised</small><strong>CarVault</strong></div><small>Photos · specs · value · history</small></div></div>
</div></section>
<div class="metricStrip"><div class="container metricGrid"><div class="metric"><strong>One private garage</strong><span>Your cars stay tied to your account</span></div><div class="metric"><strong>Real vehicle records</strong><span>Specs, images, notes and value</span></div><div class="metric"><strong>Fast collection search</strong><span>Brand, model, fuel and year</span></div><div class="metric"><strong>Built for any screen</strong><span>Desktop, tablet and mobile</span></div></div></div>
<section class="section white"><div class="container"><div class="sectionHead"><div><div class="eyebrow">Everything in one place</div><h2>A proper home for every vehicle.</h2></div><p>From your first daily driver to a growing enthusiast collection, CarVault keeps important vehicle information clean, visual and easy to retrieve.</p></div><div class="featureGrid"><article class="feature"><div class="featureIcon">01</div><h3>Visual collection</h3><p>Keep a polished card and full detail page for every vehicle, complete with your own uploaded photography.</p></article><article class="feature"><div class="featureIcon">02</div><h3>Useful garage data</h3><p>See total collection value, brand diversity, average model year and your latest additions at a glance.</p></article><article class="feature"><div class="featureIcon">03</div><h3>Private by account</h3><p>Your collection is connected to your signed-in account with password hashing, sessions and protected vehicle actions.</p></article><article class="feature"><div class="featureIcon">04</div><h3>Smart search</h3><p>Find the right car quickly by name, brand, model, fuel type or year and sort the garage the way you prefer.</p></article><article class="feature"><div class="featureIcon">05</div><h3>Keep details current</h3><p>Edit specifications, value, notes and photography whenever something changes without rebuilding your records.</p></article><article class="feature"><div class="featureIcon">06</div><h3>Works everywhere</h3><p>A responsive interface that stays clean and usable whether you are at your desk, in the garage or on your phone.</p></article></div></div></section>
<section class="section"><div class="container"><div class="sectionHead"><div><div class="eyebrow">Designed around the cars</div><h2>Less admin. More garage.</h2></div><p>High-quality photography and concise vehicle information stay at the centre of the experience instead of being buried inside tables.</p></div><div class="showcase"><div class="showcaseMain"><img src="https://images.unsplash.com/photo-1670727229851-79aee0a1d1a9?auto=format&fit=crop&w=1700&q=88" alt="Blue performance coupe"><div class="showcaseCopy"><div class="eyebrow" style="color:#b7c4ff">Vehicle profiles</div><h3>Every car gets its own space.</h3><p>Photography, model information, fuel type, colour, value and personal notes come together in one clean view.</p></div></div><div class="showcaseSide"><img src="https://images.unsplash.com/photo-1606016159991-dfe4f2746ad5?auto=format&fit=crop&w=1400&q=86" alt="Electric sedan"><div class="showcaseCopy"><div class="eyebrow" style="color:#b7c4ff">Collection insight</div><h3>Know what is in your garage.</h3><p>Keep the collection searchable and understandable as it grows.</p></div></div></div></div></section>

<?php elseif($p==='register' || $p==='login'): ?>
<section class="authPage"><div class="authVisual"><img src="<?=e($authImage)?>" alt="Performance car"><div class="authCopy"><div class="eyebrow" style="color:#b7c4ff">CarVault</div><h2>Your garage, wherever you are.</h2><p>Securely keep your collection details together and access them from any device.</p></div></div><div class="authSide"><div class="authCard"><div class="eyebrow"><?=$p==='register'?'Create your account':'Welcome back'?></div><h1><?=$p==='register'?'Start your garage.':'Sign in to CarVault.'?></h1><p><?=$p==='register'?'Create your private collection and add your first vehicle.':'Access your vehicles, values and collection details.'?></p><?php foreach($errors as $x): ?><div class="alert error" style="width:100%;margin:12px 0 0"><?=e($x)?></div><?php endforeach; ?><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="<?=$p==='register'?'register':'login'?>"><?php if($p==='register'): ?><label class="field">Full name<input name="name" required autocomplete="name" placeholder="Your name"></label><?php endif; ?><label class="field">Email address<input type="email" name="email" required autocomplete="email" placeholder="you@example.com"></label><label class="field">Password<input type="password" name="password" required autocomplete="<?=$p==='register'?'new-password':'current-password'?>" placeholder="••••••••"></label><?php if($p==='register'): ?><label class="field">Confirm password<input type="password" name="confirm" required autocomplete="new-password" placeholder="••••••••"></label><?php endif; ?><button class="btn primary" style="width:100%"><?=$p==='register'?'Create account':'Sign in'?></button></form><div class="authFoot"><?=$p==='register'?'Already have a garage? <a href="?p=login">Sign in</a>':'New to CarVault? <a href="?p=register">Create an account</a>'?></div></div></div></section>

<?php elseif($p==='dashboard'):
$s=$conn->prepare('SELECT COUNT(*) total,COALESCE(SUM(price),0) value,COUNT(DISTINCT brand) brands,COALESCE(AVG(year),0) avg_year,SUM(image_data IS NOT NULL OR image_url IS NOT NULL) with_images FROM cars WHERE user_id=?');
$s->bind_param('i',$uid);$s->execute();$st=$s->get_result()->fetch_assoc();
$r=$conn->prepare('SELECT * FROM cars WHERE user_id=? ORDER BY created_at DESC LIMIT 5');$r->bind_param('i',$uid);$r->execute();$recent=$r->get_result();
$mv=$conn->prepare('SELECT * FROM cars WHERE user_id=? ORDER BY price DESC LIMIT 1');$mv->bind_param('i',$uid);$mv->execute();$most=$mv->get_result()->fetch_assoc();
$fu=$conn->prepare('SELECT fuel_type,COUNT(*) c FROM cars WHERE user_id=? GROUP BY fuel_type ORDER BY c DESC');$fu->bind_param('i',$uid);$fu->execute();$fuelRes=$fu->get_result();$fuelData=[];while($x=$fuelRes->fetch_assoc())$fuelData[]=$x;
$totalCars=(int)$st['total'];
?>
<section class="page"><div class="container"><div class="pageHead"><div><div class="eyebrow">Garage overview</div><h1><?=e($_SESSION['user_name'])?>'s collection</h1><p class="sub">A live snapshot of what you own and what it is worth.</p></div><a class="btn primary" href="?p=add">+ Add vehicle</a></div>
<div class="stats"><div class="stat"><div class="statTop"><span>Vehicles</span><span>01</span></div><strong><?=$totalCars?></strong><small>in your collection</small></div><div class="stat"><div class="statTop"><span>Collection value</span><span>02</span></div><strong><?=inr($st['value'])?></strong><small>based on your entered values</small></div><div class="stat"><div class="statTop"><span>Brands</span><span>03</span></div><strong><?=(int)$st['brands']?></strong><small>unique manufacturers</small></div><div class="stat"><div class="statTop"><span>Average year</span><span>04</span></div><strong><?=$totalCars?round((float)$st['avg_year']):'—'?></strong><small>average model year</small></div></div>
<?php if($totalCars): ?><div class="dashGrid"><div class="panel"><div class="panelTitle"><h2>Collection highlight</h2><a class="navlink" style="color:#315efb" href="?p=collection">View all</a></div><?php if($most): ?><a class="featuredVehicle" href="?p=view&id=<?=(int)$most['id']?>"><img src="<?=imageSrc($most)?>" alt="<?=e($most['car_name'])?>"><div class="featuredCopy"><div class="brandLine" style="color:#c4cad5"><?=e($most['brand'])?> · <?=e($most['model'])?></div><h3><?=e($most['car_name'])?></h3><p><?=inr($most['price'])?> · <?=(int)$most['year']?> · <?=e($most['fuel_type'])?></p></div></a><?php endif; ?></div><div style="display:grid;gap:18px"><div class="panel"><div class="panelTitle"><h2>Fuel mix</h2><small class="muted"><?=$totalCars?> vehicles</small></div><div class="fuelRows"><?php if(!$fuelData): ?><p class="muted">No data yet.</p><?php else: foreach($fuelData as $fd): $pct=$totalCars?round(((int)$fd['c']/$totalCars)*100):0; ?><div><div class="fuelRowTop"><span><?=e($fd['fuel_type'])?></span><span><?=$fd['c']?> · <?=$pct?>%</span></div><div class="bar"><span style="width:<?=$pct?>%"></span></div></div><?php endforeach; endif; ?></div></div><div class="panel"><div class="panelTitle"><h2>Recently added</h2></div><div class="miniCars"><?php $recent->data_seek(0); while($c=$recent->fetch_assoc()): ?><a class="miniCar" href="?p=view&id=<?=(int)$c['id']?>"><img src="<?=imageSrc($c)?>" alt="<?=e($c['car_name'])?>"><div><h4><?=e($c['car_name'])?></h4><small><?=e($c['brand'])?> · <?=(int)$c['year']?></small></div><strong style="font-size:12px"><?=inr($c['price'])?></strong></a><?php endwhile; ?></div></div></div></div><?php else: ?><div class="empty"><h3>Your garage is ready.</h3><p>Add your first vehicle to start building a visual collection.</p><a class="btn primary" href="?p=add">Add your first vehicle</a></div><?php endif; ?></div></section>

<?php elseif($p==='collection'):
$search=trim($_GET['search']??'');$fuel=trim($_GET['fuel']??'');$year=trim($_GET['year']??'');$sort=$_GET['sort']??'newest';
$sql='SELECT * FROM cars WHERE user_id=?';$types='i';$params=[$uid];
if($search!==''){$sql.=' AND (car_name LIKE ? OR brand LIKE ? OR model LIKE ? OR color LIKE ?)';$like='%'.$search.'%';$types.='ssss';array_push($params,$like,$like,$like,$like);}if($fuel!==''){$sql.=' AND fuel_type=?';$types.='s';$params[]=$fuel;}if($year!==''&&ctype_digit($year)){$sql.=' AND year=?';$types.='i';$params[]=(int)$year;}
$orderMap=['newest'=>'created_at DESC','value'=>'price DESC','year'=>'year DESC','name'=>'car_name ASC'];$sql.=' ORDER BY '.($orderMap[$sort]??$orderMap['newest']);
$s=$conn->prepare($sql);$s->bind_param($types,...$params);$s->execute();$cars=$s->get_result();
?>
<section class="page"><div class="container"><div class="pageHead"><div><div class="eyebrow">Your garage</div><h1>Vehicle collection</h1><p class="sub">Browse, search and manage the cars attached to your account.</p></div><a class="btn primary" href="?p=add">+ Add vehicle</a></div><form class="filters"><input type="hidden" name="p" value="collection"><input class="wide" name="search" value="<?=e($search)?>" placeholder="Search name, brand, model or colour"><select name="fuel"><option value="">All fuel types</option><?php foreach(['Petrol','Diesel','Electric','Hybrid','CNG','Other'] as $f): ?><option value="<?=$f?>" <?=$fuel===$f?'selected':''?>><?=$f?></option><?php endforeach; ?></select><input name="year" type="number" min="1900" max="<?=date('Y')+1?>" value="<?=e($year)?>" placeholder="Year"><select name="sort"><option value="newest" <?=$sort==='newest'?'selected':''?>>Newest first</option><option value="value" <?=$sort==='value'?'selected':''?>>Highest value</option><option value="year" <?=$sort==='year'?'selected':''?>>Newest model</option><option value="name" <?=$sort==='name'?'selected':''?>>Name A–Z</option></select><button class="btn dark">Apply</button></form><div class="resultLine"><span><?=$cars->num_rows?> vehicle<?=$cars->num_rows===1?'':'s'?></span><?php if($search||$fuel||$year): ?><a href="?p=collection" style="color:#315efb;font-weight:800">Clear filters</a><?php endif; ?></div><?php if($cars->num_rows): ?><div class="cars"><?php while($c=$cars->fetch_assoc()): ?><article class="vehicleCard"><a class="vehicleImage" href="?p=view&id=<?=(int)$c['id']?>"><img src="<?=imageSrc($c)?>" alt="<?=e($c['car_name'])?>"><span class="yearPill"><?=(int)$c['year']?></span></a><div class="vehicleBody"><div class="brandLine"><?=e($c['brand'])?> · <?=e($c['model'])?></div><h3><?=e($c['car_name'])?></h3><div class="metaChips"><span class="chip"><?=e($c['fuel_type'])?></span><span class="chip"><?=e($c['color'])?></span></div><div class="price"><?=inr($c['price'])?></div><div class="cardActions"><a href="?p=view&id=<?=(int)$c['id']?>">View</a><a class="edit" href="?p=edit&id=<?=(int)$c['id']?>">Edit</a><form method="post" style="margin-left:auto"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="delete_car"><input type="hidden" name="id" value="<?=(int)$c['id']?>"><button class="del" data-confirm>Delete</button></form></div></div></article><?php endwhile; ?></div><?php else: ?><div class="empty"><h3>No vehicles found.</h3><p>Try another search or add a vehicle to your collection.</p><a class="btn primary" href="?p=add">Add vehicle</a></div><?php endif; ?></div></section>

<?php elseif($p==='add'||$p==='edit'):
$c=$editCar?:['id'=>0,'car_name'=>'','brand'=>'','model'=>'','year'=>'','color'=>'','fuel_type'=>'Petrol','price'=>'','description'=>'','image_data'=>null,'image_type'=>null,'image_url'=>null];
$currentSrc=$p==='edit'?imageSrc($c):'';
?>
<section class="page"><div class="container formWrap"><div class="pageHead"><div><div class="eyebrow"><?=$p==='edit'?'Update vehicle':'New vehicle'?></div><h1><?=$p==='edit'?'Edit vehicle':'Add to your garage'?></h1><p class="sub">Keep the record useful with accurate specifications, value and a good photo.</p></div><a class="btn light" href="?p=collection">← Collection</a></div><?php foreach($errors as $x): ?><div class="alert error" style="width:100%;margin:0 0 12px"><?=e($x)?></div><?php endforeach; ?><form method="post" enctype="multipart/form-data" class="formPanel" id="vehicleForm"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="save_car"><input type="hidden" name="id" value="<?=(int)$c['id']?>"><h2>Vehicle information</h2><div class="formGrid"><label class="field">Display name *<input name="car_name" required value="<?=e($c['car_name'])?>" placeholder="e.g. Weekend 911"></label><label class="field">Brand *<input name="brand" required value="<?=e($c['brand'])?>" placeholder="e.g. Porsche"></label><label class="field">Model *<input name="model" required value="<?=e($c['model'])?>" placeholder="e.g. 911 Carrera S"></label><label class="field">Model year *<input type="number" name="year" min="1900" max="<?=date('Y')+1?>" required value="<?=e($c['year'])?>" placeholder="2024"></label><label class="field">Colour *<input name="color" required value="<?=e($c['color'])?>" placeholder="e.g. Gentian Blue"></label><label class="field">Fuel type *<select name="fuel_type"><?php foreach(['Petrol','Diesel','Electric','Hybrid','CNG','Other'] as $f): ?><option value="<?=$f?>" <?=$c['fuel_type']===$f?'selected':''?>><?=$f?></option><?php endforeach; ?></select></label><label class="field">Estimated value (₹) *<input type="number" name="price" min="0" step="0.01" required value="<?=e($c['price'])?>" placeholder="15000000"></label><label class="field">Vehicle photo<input type="file" name="image" id="imageInput" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG or WEBP · max 1.5MB</small></label><label class="field full">Notes<textarea name="description" placeholder="Ownership story, upgrades, condition, service notes or anything worth remembering."><?=e($c['description'])?></textarea></label><div class="uploadPreview"><div class="previewBox" id="previewBox"><?php if($currentSrc): ?><img src="<?=$currentSrc?>" alt="Current vehicle image"><?php else: ?>No photo<?php endif; ?></div><div><strong>Choose a strong cover photo</strong><div class="muted" style="font-size:12px">Landscape photos work best across the dashboard and collection cards.<?php if($p==='edit'): ?> Leave the file field empty to keep the current image.<?php endif; ?></div></div></div></div><div class="formActions"><button class="btn primary"><?=$p==='edit'?'Save changes':'Add vehicle'?></button><a class="btn light" href="?p=collection">Cancel</a></div></form></div></section>

<?php elseif($p==='view'): $c=$editCar; ?>
<section class="page"><div class="container"><a class="back" href="?p=collection">← Back to collection</a><div class="detail"><div class="detailImage"><img src="<?=imageSrc($c)?>" alt="<?=e($c['car_name'])?>"></div><div class="detailPanel"><div class="eyebrow"><?=e($c['brand'])?></div><h1><?=e($c['car_name'])?></h1><p class="sub"><?=e($c['model'])?> · <?=(int)$c['year']?></p><div class="detailPrice"><?=inr($c['price'])?></div><div class="specs"><div class="spec"><span>Brand</span><strong><?=e($c['brand'])?></strong></div><div class="spec"><span>Model</span><strong><?=e($c['model'])?></strong></div><div class="spec"><span>Model year</span><strong><?=(int)$c['year']?></strong></div><div class="spec"><span>Fuel</span><strong><?=e($c['fuel_type'])?></strong></div><div class="spec"><span>Colour</span><strong><?=e($c['color'])?></strong></div><div class="spec"><span>Added</span><strong><?=date('d M Y',strtotime($c['created_at']))?></strong></div></div><?php if($c['description']): ?><div class="notes"><b>Garage notes</b><?=nl2br(e($c['description']))?></div><?php endif; ?><div class="detailActions"><a class="btn primary" href="?p=edit&id=<?=(int)$c['id']?>">Edit vehicle</a><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="delete_car"><input type="hidden" name="id" value="<?=(int)$c['id']?>"><button class="btn danger" data-confirm>Delete vehicle</button></form></div></div></div></div></section>
<?php endif; ?>
</main>

<footer class="footer"><div class="container footerInner"><a class="brand" href="?"><span class="mark"><svg viewBox="0 0 24 24" fill="none" stroke="#0b0d12" stroke-width="1.8"><path d="M4 15.5h16l-1.6-4.1a2.5 2.5 0 0 0-2.3-1.6H7.9a2.5 2.5 0 0 0-2.3 1.6L4 15.5Z"/><path d="M7 9.8 8.5 6h7L17 9.8"/></svg></span>CarVault</a><small>Private vehicle collection management · PHP + MySQL</small></div></footer>
<script>
document.querySelectorAll('[data-confirm]').forEach(b=>b.addEventListener('click',e=>{if(!confirm('Remove this vehicle from your collection? This cannot be undone.'))e.preventDefault()}));
const input=document.getElementById('imageInput'),box=document.getElementById('previewBox');
if(input&&box){input.addEventListener('change',()=>{const f=input.files&&input.files[0];if(!f)return;const u=URL.createObjectURL(f);box.innerHTML='<img src="'+u+'" alt="Image preview">';});}
</script>
</body></html>
