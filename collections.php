<?
include "include/db.php";
include "include/collections_functions.php";
# External access support (authenticate only if no key provided, or if invalid access key provided)
$k=getvalescaped("k","");if (($k=="") || (!check_access_key_collection(getvalescaped("collection",""),$k))) {include "include/authenticate.php";}

include "include/general.php";
include "include/research_functions.php";
include "include/search_functions.php";

# Hide/show thumbs
$thumbs=getval("thumbs",$thumbs_default);
setcookie("thumbs",$thumbs);
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<title><?=$applicationname?></title>
<link href="css/wrdsnpics.css" rel="stylesheet" type="text/css" media="screen,projection,print" />
<link href="css/Col-<?=getval("colourcss","greyblu")?>.css" rel="stylesheet" type="text/css" media="screen,projection,print" id="colourcss"/>
<!--[if lte IE 6]> <link href="css/wrdsnpicsIE.css" rel="stylesheet" type="text/css"  media="screen,projection,print" /> <![endif]-->
<!--[if lte IE 5.6]> <link href="css/wrdsnpicsIE5.css" rel="stylesheet" type="text/css"  media="screen,projection,print" /> <![endif]-->
</head>

<body class="CollectBack" id="collectbody">
<?
$collection=getvalescaped("collection","");
if ($collection!="")
	{
	#change current collection
	if ($k=="") {set_user_collection($userref,$collection);}
	$usercollection=$collection;
	}

$add=getvalescaped("add","");
if ($add!="")
	{
	#add to current collection
	if (add_resource_to_collection($add,$usercollection)==false)
		{ ?><script language="Javascript">alert("<?=$lang["cantmodifycollection"]?>");</script><? };
	
   	# Log this
	daily_stat("Add resource to collection",$add);
	
	# update resource/keyword kit count
	$search=getvalescaped("search","");
	if ((strpos($search,"!")===false) && ($search!="")) {update_resource_keyword_hitcount($add,$search);}
	}

$remove=getvalescaped("remove","");
if ($remove!="")
	{
	#remove from current collection
	if (remove_resource_from_collection($remove,$usercollection)==false)
		{ ?><script language="Javascript">alert("<?=$lang["cantmodifycollection"]?>");</script><? };
	}
	
$addsearch=getvalescaped("addsearch","");
if ($addsearch!="")
	{
	if (getval("mode","")=="")
		{
		#add saved search
		add_saved_search($usercollection);
		
		# Log this
		daily_stat("Add saved search to collection",0);
		}
	else
		{
		#add saved search (the items themselves rather than just the query)
		add_saved_search_items($usercollection);
		
		# Log this
		daily_stat("Add saved search items to collection",0);
		}
	}

$removesearch=getvalescaped("removesearch","");
if ($removesearch!="")
	{
	#remove saved search
	remove_saved_search($usercollection,$removesearch);
	}
	
$research=getvalescaped("research","");
if ($research!="")
	{
	$col=get_research_request_collection($research);
	if ($col==false)
		{
		$rr=get_research_request($research);
		$new=create_collection ($rr["user"],"Request: " . $rr["name"],1);
		set_user_collection($userref,$new);
		set_research_collection($research,$new);
		}
	else
		{
		set_user_collection($userref,$col);
		}
	}
	
if (file_exists("plugins/collection_process.php")) {include "plugins/collection_process.php";}
?>

<script language="Javascript">
function ToggleThumbs()
	{
	document.getElementById("collectbody").style.paddingTop="400px";
	
	<? if ($thumbs=="show") { ?>
	document.getElementById("CollectionSpace").style.visibility="hidden";
	top.document.getElementById("topframe").rows="*,3,33";
	<? } else { ?>
	top.document.getElementById("topframe").rows="*,3,128";
	<? } ?>
	}
<? if ($thumbs=="hide") { ?>
top.document.getElementById("topframe").rows="*,3,33";
<? } ?>
</script>

<? 
$searches=get_saved_searches($usercollection);
$result=do_search("!collection" . $usercollection);
$cinfo=get_collection($usercollection);

if ($thumbs=="show") { 
# ---------------------------- Maximised view
if ($k!="")
	{
	# Anonymous access, slightly different display
	$tempcol=get_collection($usercollection);
	?>
<div id="CollectionMenu">
  <h2><?=$tempcol["name"]?></h2>
	<br />
	<?=$lang["created"] . " " . nicedate($tempcol["created"])?><br />
  	<?=count($result) . " " . $lang["youfoundresources"]?><br />
	<a href="collection_download.php?collection=<?=$usercollection?>&k=<?=$k?>" target="collections">&gt;&nbsp;<?=$lang["action-download"]?></a>
</div>
<?
} else {
?>
<div id="CollectionMenu">
  <h2><?=$lang["mycollections"]?></h2>
  <form method="get" id="colselect">
		<div class="SearchItem"><?=$lang["currentcollection"]?>:
		<select name="collection" onchange="document.getElementById('colselect').submit();" class="SearchWidth">
		<?
		$list=get_user_collections($userref);
		$found=false;
		for ($n=0;$n<count($list);$n++)
			{
			?>
			<option value="<?=$list[$n]["ref"]?>" <? if ($usercollection==$list[$n]["ref"]) {?> 	selected<? $found=true;} ?>><?=htmlspecialchars($list[$n]["name"])?></option>
			<?
			}
		if ($found==false)
			{
			# Add this one at the end, it can't be found
			$notfound=get_collection($usercollection);
			?>
			<option selected><?=$notfound["name"]?></option>
			<?
			}
		?>
		</select>
		</div>				
  </form>

  <ul>
  	<? if ((!(sql_value("select count(*) value from research_request where collection='$usercollection'",0)>0)) || (!checkperm("r"))) { ?>
    		<? if (checkperm("s")) { ?><li><a href="collection_manage.php" target="main">&gt; <?=$lang["managemycollections"]?></a></li>
    <li><a href="collection_email.php?ref=<?=$usercollection?>" target="main">&gt; <?=$lang["email"]?></a>
    
    <? if (($userref==$cinfo["user"]) || (checkperm("h"))) {?>&nbsp;&nbsp;<a target="main" href="collection_edit.php?ref=<?=$usercollection?>">&gt;&nbsp;<?=$lang["edit"]?></a><? } ?>
    
    </li><? } ?>
    <? } else {
    $research=sql_value("select ref value from research_request where collection='$usercollection'",0);
    ?>
    <li><a href="team_research_edit.php?ref=<?=$research?>" target="main">&gt;<?=$lang["editresearchrequests"]?></a></li>    
    <li><a href="team_research.php" target="main">&gt; <?=$lang["manageresearchrequests"]?></a></li>    
    <? } ?>
    
    <? 
    # If this collection is (fully) editable, then display an extra edit all link
    if ((count($result)>0) && checkperm("e" . $result[0]["archive"]) && allow_multi_edit($usercollection)) { ?>
    <li><a href="search.php?search=<?=urlencode("!collection" . $usercollection)?>" target="main">&gt; <?=$lang["viewall"]?></a>
    &nbsp;&nbsp;
    <a href="edit.php?collection=<?=$usercollection?>" target="main">&gt; <?=$lang["editall"]?></a>
    </li>    
    <? } else { ?>
    <li><a href="search.php?search=<?=urlencode("!collection" . $usercollection)?>" target="main">&gt; <?=$lang["viewall"]?></a></li>
    <? } ?>
    
    <li>
   	<? if (isset($zipcommand)) { ?>
    <a target="main" href="collection_download.php?collection=<?=$usercollection?>">&gt; <?=$lang["zipall"]?></a>
    &nbsp;&nbsp;
	<? } ?>
    <a href="collections.php?thumbs=hide" onClick="ToggleThumbs();">&gt; <?=$lang["hidethumbnails"]?></a>
	</li>
  </ul>
</div>
<? } ?>

<!--Resource panels-->
<div id="CollectionSpace">

<?
# Loop through saved searches
for ($n=0;$n<count($searches);$n++)			
		{
		$ref=$searches[$n]["ref"];
		$url="search.php?search=" . urlencode($searches[$n]["search"]) . "&restypes=" . urlencode($searches[$n]["restypes"]) . "&archive=" . $searches[$n]["archive"];
		?>
		<!--Resource Panel-->
		<div class="CollectionPanelShell">
		<table border="0" class="CollectionResourceAlign"><tr><td>
		<a target="main" href="<?=$url?>"><img border=0 width=56 height=75 src="gfx/images/save-search.gif"/></a></td>
		</tr></table>
		<div class="CollectionPanelInfo"><a target="main" href="<?=$url?>"><?=$lang["savedsearch"]?> <?=$n+1?></a>&nbsp;</div>
		<div class="CollectionPanelInfo"><a href="collections.php?removesearch=<?=$ref?>&nc=<?=time()?>">x <?=$lang["action-remove"]?>
</a></div>				
		</div>
		<?		
		}

# Loop through thumbnails
if (count($result)>0) 
	{
	# loop and display the results
	for ($n=0;$n<count($result);$n++)			
		{
		$ref=$result[$n]["ref"];
		?>
<? if (!hook("resourceview")) { ?>
		<!--Resource Panel-->
		<div class="CollectionPanelShell">
		<table border="0" class="CollectionResourceAlign"><tr><td>
		<a target="main" href="view.php?ref=<?=$ref?>&search=<?=urlencode("!collection" . $usercollection)?>&k=<?=$k?>"><? if ($result[$n]["has_image"]==1) { ?><img border=0 src="<?=get_resource_path($ref,"col",false,$result[$n]["preview_extension"])?>" class="CollectImageBorder"/><? } else { ?><img border=0 src="gfx/type<?=$result[$n]["resource_type"]?>_col.gif"/><? } ?></a></td>
		</tr></table>
		<div class="CollectionPanelInfo"><a target="main" href="view.php?ref=<?=$ref?>&search=<?=urlencode("!collection" . $usercollection)?>&k=<?=$k?>"><?=tidy_trim($result[$n]["title"],14)?></a>&nbsp;</div>
		<? if ($k=="") { ?><div class="CollectionPanelInfo"><a href="collections.php?remove=<?=$ref?>&nc=<?=time()?>">x <?=$lang["action-remove"]?></a></div><? } ?>			
		</div>
<? } ?>		
		<?		
		}
	}

	# Plugin for additional collection listings	
	if (file_exists("plugins/collection_listing.php")) {include "plugins/collection_listing.php";}
	?>
	</div>
	<?
}
else
{
# ------------------------- Minimised view
?>
<!--Title-->	
<div id="CollectionMinTitle"><h2><?=$lang["mycollections"]?></h2></div>

<!--Menu-->	
<div id="CollectionMinRightNav">
  <ul>
  	<? if ((!(sql_value("select count(*) value from research_request where collection='$usercollection'",0)>0)) || (!checkperm("r"))) { ?>
    		<? if (checkperm("s")) { ?><li><a href="collection_manage.php" target="main"><?=$lang["managemycollections"]?></a></li>
    <li><a href="collection_email.php?ref=<?=$usercollection?>" target="main"><?=$lang["email"]?></a></li><? } ?>
        <? if (($userref==$cinfo["user"]) || (checkperm("h"))) {?><li><a target="main" href="collection_edit.php?ref=<?=$usercollection?>"><?=$lang["edit"]?></a></li><? } ?>
    <? } else {
    $research=sql_value("select ref value from research_request where collection='$usercollection'",0);
    ?>
    <li><a href="team_research_edit.php?ref=<?=$research?>" target="main"><?=$lang["editresearchrequests"]?></a></li>    
    <li><a href="team_research.php" target="main"><?=$lang["manageresearchrequests"]?></a></li>    
    <? } ?>
    <? 
    # If this collection is (fully) editable, then display an extra edit all link
    if ((count($result)>0) && checkperm("e" . $result[0]["archive"]) && allow_multi_edit($usercollection)) { ?>
    <li><a href="search.php?search=<?=urlencode("!collection" . $usercollection)?>" target="main"><?=$lang["viewall"]?></a></li>
    <li><a href="edit.php?collection=<?=$usercollection?>" target="main"><?=$lang["editall"]?></a></li>    
    <? } else { ?>
    <li><a href="search.php?search=<?=urlencode("!collection" . $usercollection)?>" target="main"><?=$lang["viewall"]?></a></li>
    <? } ?>
   	<? if (isset($zipcommand)) { ?>
    <li><a target="main" href="collection_download.php?collection=<?=$usercollection?>"><?=$lang["zipall"]?></a></li>
	<? } ?>
    <li><a href="collections.php?thumbs=show" onClick="ToggleThumbs();"><?=$lang["showthumbnails"]?></a></li>
  </ul>
</div>

<!--Collection Dropdown-->	
<div id="CollectionMinDropTitle"><?=$lang["currentcollection"]?>:&nbsp;</div>				
<div id="CollectionMinDrop">
<form id="colselect" method="get">
		<div class="MinSearchItem">
		<select name="collection" class="SearchWidth" onchange="document.getElementById('colselect').submit();">
		<?
		$found=false;
		$list=get_user_collections($userref);
		for ($n=0;$n<count($list);$n++)
			{
			?>
			<option value="<?=$list[$n]["ref"]?>" <? if ($usercollection==$list[$n]["ref"]) {?> selected<? $found=true;}?>><?=htmlspecialchars($list[$n]["name"])?></option>
			<?
			}
		if ($found==false)
			{
			# Add this one at the end, it can't be found
			$notfound=get_collection($usercollection);
			?>
			<option selected><?=$notfound["name"]?></option>
			<?
			}
		?>
		</select>
		
		</div>				
  </form>
</div>
	
<!--Collection Count-->	
<div id="CollectionMinitems"><strong><?=count($result)?></strong>&nbsp;<?=$lang["items"]?></div>		
<? } ?>



</body>
</html>
