<?php if ($p === 'browse'):
    $search = trim($_GET['search'] ?? '');
    $like = '%'.$search.'%';
    $cars = rq($conn, "SELECT c.id,c.car_name,c.brand,c.model,c.year,c.color,c.fuel_type,c.price,c.image_data,c.image_type,c.image_url,l.is_demo,
        EXISTS(SELECT 1 FROM reservations r WHERE r.car_id=c.id AND r.status IN ('pending','confirmed')) AS reserved
        FROM cars c JOIN car_listings l ON l.car_id=c.id AND l.is_listed=1
        WHERE c.car_name LIKE ? OR c.brand LIKE ? OR c.model LIKE ? ORDER BY l.is_demo,c.created_at DESC", 'sss', $like,$like,$like)->get_result();
?>
<section class="page"><div class="container">
  <div class="pageHead"><div><div class="eyebrow">Find your next car</div><h1>Cars to reserve</h1><p class="sub">Explore listed vehicles and track every request in your user panel.</p></div><a class="btn light" href="?p=user_panel">My reservations</a></div>
  <form class="reservationSearch"><input type="hidden" name="p" value="browse"><label class="field" for="carSearch">Find a car<input id="carSearch" name="search" value="<?=e($search)?>" placeholder="Search brand, model or car name"></label><button class="btn dark">Search</button><?php if($search): ?><a href="?p=browse" class="btn light">Clear</a><?php endif; ?></form>
  <p class="sub" style="margin:18px 0"><?=$cars->num_rows?> listed vehicle<?=$cars->num_rows===1?'':'s'?> · Requests are subject to owner confirmation. No payment is collected.</p>
  <?php if (!$cars->num_rows): ?><div class="empty"><h3><?=$search?'No matching cars.':'New listings are on the way.'?></h3><p><?=$search?'Try another brand or model.':'Vehicle owners can publish a car from its collection detail page.'?></p><a class="btn primary" href="<?=$search?'?p=browse':'?p=collection'?>"><?=$search?'View all cars':'Open my collection'?></a></div>
  <?php else: ?><div class="cars"><?php while($c=$cars->fetch_assoc()): $available=(int)$c['is_demo'] || !(int)$c['reserved']; ?>
  <article class="vehicleCard"><a class="vehicleImage" href="?p=reserve&id=<?=(int)$c['id']?>"><img loading="lazy" src="<?=imageSrc($c)?>" alt="<?=e($c['car_name'])?>"><span class="yearPill"><?=(int)$c['year']?></span></a>
    <div class="vehicleBody"><div class="brandLine"><?=e($c['brand'])?> · <?=e($c['model'])?></div><h3><?=e($c['car_name'])?></h3><div class="metaChips"><span class="chip"><?=e($c['fuel_type'])?></span><span class="chip"><?=e($c['color'])?></span><span class="status <?=$available?'confirmed':'pending'?>"><?=(int)$c['is_demo']?'Demo car':($available?'Available':'Reserved')?></span></div><div class="price"><?=inr($c['price'])?></div><small class="sub">Listed vehicle value</small><div class="cardActions"><a href="?p=reserve&id=<?=(int)$c['id']?>"><?=$available?'View & reserve':'View details'?> →</a></div></div>
  </article><?php endwhile; ?></div><?php endif; ?>
</div></section>

<?php elseif ($p === 'reserve'): $c=listing($conn,(int)($_GET['id']??0)); ?>
<section class="page"><div class="container"><a class="back" href="?p=browse">← All listed cars</a>
<?php if(!$c): ?><div class="empty"><h1>Listing unavailable</h1><p>This car may have been removed from the public listings.</p><a class="btn primary" href="?p=browse">Browse cars</a></div>
<?php else: $demo=(int)$c['is_demo']; ?>
<div class="detail"><div class="detailImage"><img src="<?=imageSrc($c)?>" alt="<?=e($c['car_name'])?>"></div><div class="detailPanel"><div class="eyebrow"><?=$demo?'Demo vehicle':e($c['brand'])?></div><h1><?=e($c['car_name'])?></h1><p class="sub"><?=e($c['model'])?> · <?=(int)$c['year']?></p><div class="detailPrice"><?=inr($c['price'])?><small class="sub" style="display:block;font-size:13px;font-weight:500">Listed vehicle value · no payment at reservation</small></div>
<div class="specs"><?php foreach(['brand'=>'Brand','model'=>'Model','year'=>'Year','fuel_type'=>'Fuel','color'=>'Colour'] as $key=>$label): ?><div class="spec"><span><?=$label?></span><strong><?=e($c[$key])?></strong></div><?php endforeach; ?><div class="spec"><span>Availability</span><strong><?=$demo?'Demo available':($c['reserved']?'Reserved':'Available')?></strong></div></div>
<p class="sub" style="margin-top:22px"><?=$demo?'Try the full booking flow with this sample car. Demo reservations are confirmed automatically and do not reserve a real vehicle.':'Submit your request, then check your user panel for owner confirmation. A request does not complete a purchase.'?></p>
<div class="detailActions">
<?php if((int)$c['user_id']===$uid): ?><a class="btn primary" href="?p=view&id=<?=(int)$c['id']?>">Manage your listing</a>
<?php elseif(!$demo && $c['reserved']): ?><span class="status pending">Currently reserved</span><a class="btn light" href="?p=browse">Browse other cars</a>
<?php elseif(!logged()): ?><a class="btn primary" href="?p=login&next_car=<?=(int)$c['id']?>">Sign in to reserve</a>
<?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="reserve_car"><input type="hidden" name="id" value="<?=(int)$c['id']?>"><button class="btn primary"><?=$demo?'Create demo reservation':'Request reservation'?></button></form><?php endif; ?>
</div></div></div><?php endif; ?></div></section>

<?php elseif($p==='user_panel'):
    $incoming=($_GET['tab']??'')==='requests';
    $stats=rq($conn,"SELECT COUNT(*) total,COALESCE(SUM(status='pending'),0) pending,COALESCE(SUM(status='confirmed'),0) confirmed,COALESCE(SUM(status IN ('cancelled','declined')),0) closed FROM reservations WHERE user_id=?",'i',$uid)->get_result()->fetch_assoc();
    $waiting=rq($conn,"SELECT COUNT(*) total FROM reservations WHERE owner_id=? AND status='pending' AND is_demo=0",'i',$uid)->get_result()->fetch_assoc()['total'];
    $sql=$incoming?"SELECT r.*,u.name customer_name,u.email customer_email FROM reservations r JOIN users u ON u.id=r.user_id WHERE r.owner_id=? AND r.is_demo=0 ORDER BY r.created_at DESC,r.id DESC":"SELECT r.* FROM reservations r WHERE r.user_id=? ORDER BY r.created_at DESC,r.id DESC";
    $reservations=rq($conn,$sql,'i',$uid)->get_result();
?>
<section class="page"><div class="container">
<div class="pageHead"><div><div class="eyebrow">Your user panel</div><h1>Hello, <?=e($_SESSION['user_name'])?>.</h1><p class="sub">Your reservations, updates and private garage in one place.</p></div><a class="btn primary" href="?p=browse">Browse cars →</a></div>
<div class="stats"><?php foreach(['total'=>'Total reservations','pending'=>'Awaiting confirmation','confirmed'=>'Confirmed','closed'=>'Closed'] as $k=>$label): ?><div class="stat"><div class="statTop"><?=$label?></div><strong><?=(int)$stats[$k]?></strong><small><?=$k==='confirmed'?'Includes demo reservations':'Your booking activity'?></small></div><?php endforeach; ?></div>
<nav class="panelTabs" aria-label="User panel"><a class="<?=$incoming?'':'active'?>" href="?p=user_panel">My reservations</a><a class="<?=$incoming?'active':''?>" href="?p=user_panel&tab=requests">Incoming requests<?php if($waiting): ?> <b><?=(int)$waiting?></b><?php endif; ?></a><a href="?p=dashboard">Garage dashboard ↗</a><a href="?p=collection">My collection ↗</a></nav>
<?php if(!$reservations->num_rows): ?><div class="empty"><h2><?=$incoming?'No incoming requests yet.':'Your next car starts here.'?></h2><p><?=$incoming?'List a vehicle from its detail page to receive and review reservation requests.':'Browse available cars and submit your first reservation. Your status and reference will appear here.'?></p><a class="btn primary" href="<?=$incoming?'?p=collection':'?p=browse'?>"><?=$incoming?'Open my collection':'Find a car'?></a></div>
<?php else: ?><div class="reservationList"><?php while($r=$reservations->fetch_assoc()): ?>
<article class="panel bookingRow"><div><div class="brandLine"><?=e($r['reference'])?></div><h2><?=e($r['car_name'])?></h2><p class="sub">Requested <?=date('d M Y',strtotime($r['created_at']))?> · <?=inr($r['listed_value'])?> listed value</p><?php if($incoming): ?><p>Requested by <?=e($r['customer_name'])?> · <a href="mailto:<?=e($r['customer_email'])?>"><?=e($r['customer_email'])?></a></p><?php endif; ?><p class="bookingHint"><?php if($r['is_demo']): ?>Demo only — no real vehicle or payment is involved.<?php elseif($r['status']==='pending'): ?>Waiting for the vehicle owner to review this request.<?php elseif($r['status']==='confirmed'): ?>The owner has confirmed this reservation. Arrange the next steps directly; no payment has been collected.<?php else: ?>This reservation is closed. You can browse cars and submit a new request.<?php endif; ?></p></div>
<div class="bookingActions"><span class="status <?=e($r['status'])?>"><?=ucfirst(e($r['status']))?></span>
<?php if($r['car_id']): ?><a class="btn light" href="?p=reserve&id=<?=(int)$r['car_id']?>">View car</a><?php endif; ?>
<?php if(!$incoming && in_array($r['status'],['pending','confirmed'],true)): ?><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="cancel_reservation"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn danger" data-booking-confirm="Cancel this reservation?">Cancel reservation</button></form>
<?php elseif($incoming && in_array($r['status'],['pending','confirmed'],true)): ?>
<?php foreach($r['status']==='pending'?['confirmed'=>'Confirm','declined'=>'Decline']:['cancelled'=>'Cancel reservation'] as $status=>$label): ?><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="review_reservation"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input type="hidden" name="status" value="<?=$status?>"><button class="btn <?=$status==='confirmed'?'primary':'danger'?>" data-booking-confirm="<?=$label?> this request?"><?=$label?></button></form><?php endforeach; ?>
<?php endif; ?></div></article><?php endwhile; ?></div><?php endif; ?>
</div></section>
<?php endif; ?>
