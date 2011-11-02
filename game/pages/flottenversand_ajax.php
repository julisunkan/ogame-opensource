<?php

// Быстрая отправка флотов из Галактики посредством AJAX.

// Переменные снаружи : aktplanet - текущая планета.

BrowseHistory ();

$uni = LoadUniverse ();
if ( $uni['freeze'] ) AjaxSendError ();    // Вселенная на паузе.
$unispeed = $uni['speed'];
$fleetmap = array ( 202, 203, 204, 205, 206, 207, 208, 209, 210, 211, 212, 213, 214, 215 );

function AjaxSendError ($id=601)
{
    header ('Content-Type: text/html;');
    echo "$id 0 0 0 0";
    ob_end_flush ();
    die ();
}

function AjaxSendDone ($slots, $probes, $recyclers, $missiles)
{
    header ('Content-Type: text/html;');
    echo "600 $slots $probes $recyclers $missiles";
    ob_end_flush ();
    die ();
}

// Проверить наличие параметров.
if (
    !key_exists ( "order", $_POST ) ||
    !key_exists ( "galaxy", $_POST ) ||
    !key_exists ( "system", $_POST ) ||
    !key_exists ( "planet", $_POST ) ||
    !key_exists ( "planettype", $_POST ) ||
    !key_exists ( "shipcount", $_POST ) 
 )  AjaxSendError ();

$order = $_POST['order'];
$galaxy = $_POST['galaxy'];
$system = $_POST['system'];
$planet = $_POST['planet'];
$planettype = $_POST['planettype'];
$shipcount = abs ($_POST['shipcount']);
$speed = 1;

// Проверить параметры.

if ( $planettype < 1 || $planettype > 3 ) AjaxSendError ();    // неверная цель
if ( ! ( $order == 6 || $order == 8 ) ) AjaxSendError ();    // можно отправлять только шпионаж или переработать
if ( $order == 8 && $planettype != 2 ) AjaxSendError ();    // рабов можно отправлять только на ПО
if ( $order == 6 && ! ($planettype == 1 || $planettype == 3) )  AjaxSendError ();     // шпионить можно только планеты или луны
if ( $galaxy < 1 || $galaxy > $uni['galaxies'] ) AjaxSendError ();    // неправильные координаты (Галактика)
if ( $system < 1 || $system > $uni['systems'] ) AjaxSendError ();    // неправильные координаты (Система)
if ( $planet < 1 || $planet > 15 ) AjaxSendError ();    // неправильные координаты (Позиция)

// Проверить свободные слоты
$result = EnumOwnFleetQueue ( $GlobalUser['player_id'] );
$nowfleet = dbrows ($result);
$maxfleet = $GlobalUser['r108'] + 1;
if ( $nowfleet >= $maxfleet ) AjaxSendError (612);

$target = LoadPlanet ( $galaxy, $system, $planet, $planettype );    // загрузить целевую планету
if ( $target == NULL )
{
    if ($planettype == 1) AjaxSendError (614);        // нет планеты
    else if ($planettype == 3) AjaxSendError (602);    // нет луны
    else AjaxSendError ();    // нет ПО
}

$target_user = LoadUser ( $target['owner_id'] );

$probes = $aktplanet['f210'];
$recyclers = $aktplanet['f209'];
$missiles = $aktplanet['d503'];

/* ************ ШПИОНАЖ ************  */

if ( $order == 6 )
{
    $amount = min ($aktplanet["f210"], $shipcount);

    if ( $target['owner_id'] == $GlobalUser['player_id'] ) AjaxSendError ();    // Своя планета
    if ( $GlobalUser['noattack'] ) AjaxSendError ();    // Бан атак
    if ( $target_user['admin'] > 0 ) AjaxSendError ();    // администрацию сканить нельзя
    if ( IsPlayerNewbie ($target_user['player_id']) ) AjaxSendError (603);    // защита новичков
    if ( IsPlayerStrong ($target_user['player_id']) ) AjaxSendError (604);    // защита сильных
    if ( $target_user['vacation'] ) AjaxSendError (605);    // игрок в режиме отпуска
    if ( $amount == 0 ) AjaxSendError (611);    // нет кораблей для отправки
    if ( $GlobalUser['ip_addr'] !== "127.0.0.1" ) {
        if ( $target_user['ip_addr'] === $GlobalUser['ip_addr'] ) AjaxSendError (616);    // мультиалярм
    }

    // Сформировать флот.
    $fleet = array ( 0, 0, );
    foreach ( $fleetmap as $i=>$gid ) {
        if ( $gid == 210 ) $fleet[$gid] = $amount;
        else $fleet[$gid] = 0;
    }
    $cargo = FleetCargo (210) * $amount;
    $probes -= $amount;
}

/* ************ ПЕРЕРАБОТАТЬ ************  */

if ( $order == 8 )
{
    $amount = min ($aktplanet["f209"], $shipcount);

    if ( $amount == 0 ) AjaxSendError (611);    // нет кораблей для отправки

    // Сформировать флот.
    $fleet = array ( 0, 0, );
    foreach ( $fleetmap as $i=>$gid ) {
        if ( $gid == 209 ) $fleet[$gid] = $amount;
        else $fleet[$gid] = 0;
    }
    $cargo = FleetCargo (209) * $amount;
    $recyclers -= $amount;
}

// Рассчитать расстояние, время полёта и затраты дейтерия.
$probeOnly = false;
$dist = FlightDistance ( $aktplanet['g'], $aktplanet['s'], $aktplanet['p'], $galaxy, $system, $planet );
$slowest_speed = FlightSpeed ( $fleet, $GlobalUser['r115'], $GlobalUser['r117'], $GlobalUser['r118'] );
$flighttime = FlightTime ( $dist, $slowest_speed, $speed, $unispeed );
$cons = FlightCons ( $fleet, $dist, $flighttime, $slowest_speed, $GlobalUser['r115'], $GlobalUser['r117'], $GlobalUser['r118'], $probeOnly );

if ( $aktplanet['d'] < $cons ) AjaxSendError (613);        // не хватает дейта на полёт
if ( $cargo < $cons ) AjaxSendError (615);        // нет места в грузовом отсеке для дейтерия

// Отправить флот.
DispatchFleet ( $fleet, $aktplanet, $target, $order, $flighttime, 0, 0, 0, $cons, time(), 0 );

// Поднять флот с планеты.
AdjustResources ( 0, 0, $cons, $aktplanet['planet_id'], '-' );
AdjustShips ( $fleet, $aktplanet['planet_id'], '-' );
UpdatePlanetActivity ($aktplanet['planet_id']);

AjaxSendDone ( $nowfleet+1, $probes, $recyclers, $missiles );
?>