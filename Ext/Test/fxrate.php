<?php
	
include('../fxrate.inc.php');
	
echo "<br>eur/usd: "; echo json_encode(fxRate('EUR','USD'));
echo "<br>eth/usd: "; echo json_encode(fxRate('ETH','USD'));
echo "<br>usd/eth: "; echo json_encode(fxRate('usd','eth'));
echo "<br>btc/btc: "; echo json_encode(fxRate('BTC','BtC'));
echo "<br>btc/btc: "; echo json_encode(fxRate('BTC','usd'));
echo "<br>btc/btc: "; echo json_encode(fxRate('BTC','usd',2));
echo "<br>usd/btc: "; echo json_encode(fxRate('usd','btc',2));
echo "<br>usd/btc: "; echo json_encode(fxRate('usd','btc'));


echo "<hr>5 Euros get's you ".(5*fxHtml('eur','thb'))." Thai Baht today.";
