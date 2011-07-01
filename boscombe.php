<?php

ini_set("display_errors", true);

define("ENDPOINT_CCO", "http://semsorgrid.ecs.soton.ac.uk:8000/sparql/");
define("ENDPOINT_EUROSTAT", "http://www4.wiwiss.fu-berlin.de/eurostat/sparql");
define("ENDPOINT_OS", "http://api.talis.com/stores/ordnance-survey/services/sparql");
define("ENDPOINT_LINKEDGEODATA", "http://linkedgeodata.org/sparql/");
define("ENDPOINT_DBPEDIA", "http://dbpedia.org/sparql/");

define("PROP_WINDWAVEHEIGHT", "http://marinemetadata.org/2005/08/ndbc_waves#Wind_Wave_Height");

$startURI = "http://id.semsorgrid.ecs.soton.ac.uk/sensors/cco/boscombe";
if (isset($_GET["uri"]))
	$startURI = $_GET["uri"];

// include the ARC2 libraries
require_once "arc2/ARC2.php";
require_once "Graphite/graphite/Graphite.php";

$ns = array(
	"geonames" => "http://www.geonames.org/ontology#",
	"geo" => "http://www.w3.org/2003/01/geo/wgs84_pos#",
	"foaf" => "http://xmlns.com/foaf/0.1/",
	"om" => "http://www.opengis.net/om/1.0/",
	"om2" => "http://rdf.channelcoast.org/ontology/om_tmp.owl#",
	"gml" => "http://www.opengis.net/gml#",
	"xsi" => "http://schemas.opengis.net/om/1.0.0/om.xsd#",
	"rdf" => "http://www.w3.org/1999/02/22-rdf-syntax-ns#",
	"rdfs" => "http://www.w3.org/2000/01/rdf-schema#",
	"owl" => "http://www.w3.org/2002/07/owl#",
	"pv" => "http://purl.org/net/provenance/ns#",
	"xsd" => "http://www.w3.org/2001/XMLSchema#",
	"dc" => "http://purl.org/dc/elements/1.1/",
	"lgdo" => "http://linkedgeodata.org/ontology/",
	"georss" => "http://www.georss.org/georss/",
	"eurostat" => "http://www4.wiwiss.fu-berlin.de/eurostat/resource/eurostat/",
	"postcode" => "http://data.ordnancesurvey.co.uk/ontology/postcode/",
	"admingeo" => "http://data.ordnancesurvey.co.uk/ontology/admingeo/",
	"skos" => "http://www.w3.org/2004/02/skos/core#",
	"dbpedia-owl" => "http://dbpedia.org/ontology/",
	"ssn" => "http://purl.oclc.org/NET/ssnx/ssn#",
	"ssne" => "http://www.semsorgrid4env.eu/ontologies/SsnExtension.owl#",
	"DUL" => "http://www.loa-cnr.it/ontologies/DUL.owl#",
	"time" => "http://www.w3.org/2006/time#",
	"sw" => "http://sweet.jpl.nasa.gov/2.1/sweetAll.owl#",
	"id-semsorgrid" => "http://id.semsorgrid.ecs.soton.ac.uk/",
	"sciUnits" => "http://sweet.jpl.nasa.gov/2.0/sciUnits.owl#",
	"dctypes" => "http://purl.org/dc/dcmitype/",
	"osgb" => "http://data.ordnancesurvey.co.uk/id/",
);

// load linked data
$graph = new Graphite($ns);
$graph->cacheDir("cache/graphite");
$triples = $graph->load($startURI);
if ($triples < 1)
	die("failed to load any triples from '$startURI'");

// do we have a sensor? if so, get from there to the latest wave height readings
$resource = $graph->resource($startURI);
if ($resource->isType("ssn:SensingDevice")) {
	$summaries = $graph->allOfType("ssne:PropertySummary");
	if (count($summaries) == 0)
		die("can't get to observations from sensor unless it has summaries");
	$waveheightsummaries = array();
	foreach ($summaries as $summary) {
		if ($summary->get("ssne:forMeasuredProperty") == PROP_WINDWAVEHEIGHT) {
			$summary->load();
			$waveheightsummaries[] = $summary;
		}
	}

	// latest lastobservation
	$lastobservation = null;
	foreach ($waveheightsummaries as $summary) {
		$observation = $summary->get("ssne:hasLastObservation");
		if ($observation->isNull())
			continue;
		if (is_null($lastobservation) || observationdate($observation) > observationdate($lastobservation))
			$lastobservation = $observation;
	}

	// get collection that observation's summary is summarizing
	$toplevelcollection = $lastobservation->get("-ssne:hasLastObservation")->get("-ssne:hasPropertySummary");

	// load the last observation
	$lastobservation->load();

	// get the collection it's part of
	$collection = $lastobservation->get("-DUL:hasMember");
} else {
	$collection = $graph->allOfType("ssne:ObservationCollection");
	$toplevelcollection = $collection;
	if (count($collection) != 1)
		die("expected exactly 1 collection");
	$collection = $collection->current();
}

// get summary
$summaries = $toplevelcollection->all("ssne:hasPropertySummary");
$summary = null;
foreach ($summaries as $s) {
	if ($s->get("ssne:forMeasuredProperty") == PROP_WINDWAVEHEIGHT) {
		$summary = $s;
		$summary->load();
		$mean = floatVal((string) $summary->get("ssne:hasMeasuredMeanValue")->get("ssne:hasQuantityValue"));
		break;
	}
}

// collect observations
$observations = getobservations($collection);
usort($observations, "sortbydate");

if (empty($observations))
	die("no observations");

// get URIs of previous and next observations for pagination
$prevobservation = $observations[0]->get("DUL:directlyFollows");
if ($prevobservation->isNull())
	$prevobservation = null;
$nextobservation = $observations[count($observations) - 1]->get("DUL:directlyPrecedes");
if ($nextobservation->isNull())
	$nextobservation = null;

$timesandheights = array();
foreach ($observations as $observationNode) {
	$timeNode = $observationNode->get("ssn:observationResultTime");
	$time = strtotime($timeNode->get("time:hasBeginning"));
	$timesandheights[] = array($time, floatVal((string) $observationNode->get("ssn:observationResult")->get("ssn:hasValue")->get("ssne:hasQuantityValue")));
}

$unit = $observations[0]->get("ssn:observationResult")->get("ssn:hasValue")->get("ssne:hasQuantityUnitOfMeasure");
$unit->load();

if (isset($_GET["chart"]))
	ok(json_encode(array(
		"data" => timestamptomilliseconds($timesandheights),
		"source" => $collection->uri,
		"prev" => is_null($prevobservation) ? null : $prevobservation->uri,
		"next" => is_null($nextobservation) ? null : $nextobservation->uri,
	)), "application/json");

// get sensor URI
$sensor = $graph->allOfType("ssn:Observation")->get("ssn:observedBy")->distinct()->current();
if ($sensor->isNull())
	die("no results yet today");
$sensorURI = $sensor->uri;

// get sensor coordinates
if ($graph->load($sensorURI) == 0)
	die("couldn't load sensor RDF");
$location = $graph->resource($sensorURI)->get("ssn:hasDeployment")->get("ssn:deployedOnPlatform")->get("sw:hasLocation");
if ($location->isNull())
	die("couldn't get sensor coordinates");
$coords = array(
	floatVal((string) $location->get("sw:coordinate2")->get("sw:hasNumericValue")),
	floatVal((string) $location->get("sw:coordinate1")->get("sw:hasNumericValue")),
);

// do we have a foaf:based_near from which we can get place name, district and 
// region name?
$based_near = $graph->resource($sensorURI)->get("foaf:based_near");
$placename = null;
$district = null;
$euroRegion = null;
if (!$based_near->isNull()) {
	$based_near->load();

	if ($based_near->hasLabel())
		$placename = $based_near->label();

	$based_near->get("admingeo:inDistrict")->load();
	if ($based_near->get("admingeo:inDistrict")->hasLabel())
		$district = $based_near->get("admingeo:inDistrict")->label();

	$based_near->get("admingeo:inEuropeanRegion")->load();
	if ($based_near->get("admingeo:inEuropeanRegion")->hasLabel())
		$euroRegion = $based_near->get("admingeo:inEuropeanRegion")->label();
}

if (is_null($district) || is_null($euroRegion)) {
	// get nearby postcode
	$pcgraph = new Graphite($ns);
	$pcgraph->cacheDir("cache/graphite");
	if ($pcgraph->load("http://www.uk-postcodes.com/latlng/$coords[0],$coords[1].rdf") == 0)
		die("failed to get postcode from uk-postcodes.com");
	foreach ($pcgraph->allSubjects() as $subject)
		$subject->loadSameAs();
	$postcode = $pcgraph->allOfType("postcode:PostcodeUnit")->current();

	// query O/S SPARQL endpoint for region names
	$row = sparqlquery(ENDPOINT_OS, "
		SELECT ?euroLabel ?distLabel
		WHERE {
			<" . $postcode->get("postcode:district") . ">
				rdfs:label ?distLabel ;
				admingeo:inEuropeanRegion ?euroRegion .
			?euroRegion
				rdfs:label ?euroLabel .
		}
	", "row");
	$district = $row['distLabel'];
	$euroRegion = $row['euroLabel'];
}

// fall back to district name if we don't have a place name yet
if (is_null($placename))
	$placename = $district;

// get national average per region
$rows = sparqlquery(ENDPOINT_EUROSTAT, "
	SELECT DISTINCT ?region ?injured ?killed ?population WHERE {
		?ourregion
			a eurostat:regions ;
			eurostat:name \"$euroRegion\" ;
			eurostat:parentcountry ?country .
		?region
			a eurostat:regions ;
			eurostat:parentcountry ?country ;
			eurostat:population_total ?population ;
			eurostat:injured_in_road_accidents ?injured ;
			eurostat:killed_in_road_accidents ?killed .
	}
");

// hacky filtering function to get rid of duplicate west midlandses
function stripduplicateregions($row) {
	static $seen = array();
	if (in_array($row["region"], $seen))
		return false;
	$seen[] = $row["region"];
	return true;
}
$rows = array_filter($rows, "stripduplicateregions");

// count people who have been hurt
$sum = array("injured" => 0, "killed" => 0);
foreach ($rows as $row) {
	$sum["injured"] += $row["injured"] / $row["population"];
	$sum["killed"] += $row["killed"] / $row["population"];
}
$average = array(
	"injured" => $sum["injured"] / count($rows),
	"killed" => $sum["killed"] / count($rows)
);

// retrieve road accident stats for the given region
$regionstats = sparqlquery(ENDPOINT_EUROSTAT, "
	SELECT ?injuredtotal ?killedtotal ?population
	WHERE {
		?region
			a eurostat:regions ;
			eurostat:name \"$euroRegion\" ;
			eurostat:population_total ?population ;
			eurostat:injured_in_road_accidents ?injuredtotal ;
			eurostat:killed_in_road_accidents ?killedtotal ;
	}
", "row");
$regionstats["injured"] = $regionstats["injuredtotal"] / $regionstats["population"];
$regionstats["killed"] = $regionstats["killedtotal"] / $regionstats["population"];

// find other sensors with the same observed property
$results = sparqlquery(ENDPOINT_CCO, "
	SELECT ?sensor ?sensorname
	WHERE {
		?obs
			a ssn:Observation ;
			ssn:observedProperty <" . PROP_WINDWAVEHEIGHT . "> ;
			ssn:observedBy ?sensor ;
		.
		OPTIONAL {
			?sensor rdfs:label ?sensorname .
		}
		FILTER (?sensor != <$sensorURI>)
	}
");
$otherwavesensors = array();
foreach ($results as $result) {
	$found = false;
	foreach ($otherwavesensors as $otherwavesensor) {
		if ($otherwavesensor["sensor"] == $result["sensor"]) {
			$found = true;
			break;
		}
	}
	if (!$found)
		$otherwavesensors[] = $result;
}

$types_pub = array(
	"lgdo:Pub",
	"lgdo:Bar",
);
$types_cafe = array(
	"lgdo:CoffeeShop",
	"lgdo:Cafe",
	"lgdo:InternetCafe",
);
$types_food = array(
	"lgdo:Restaurant",
	"lgdo:FastFood",
	"lgdo:Barbeque",
	"lgdo:IceCream",
);
$types_store = array(
	"lgdo:Shops",
	"lgdo:Shop",
	"lgdo:Shopping",
	"lgdo:Supermarket",
	"lgdo:Bakery",
	"lgdo:Marketplace",
	"lgdo:PublicMarket",
	"lgdo:TakeAway",
	"lgdo:DrinkingWater",
	"lgdo:WaterFountain",
	"lgdo:WaterWell",
);

$types_parking = array(
	"lgdo:Parking",
	"lgdo:MotorcycleParking",
	"lgdo:BicycleParking",
);

$types_accommodation = array(
	"lgdo:Hotel",
	"lgdo:Campsite",
);

$types_transport = array(
	"lgdo:FerryTerminal",
	"lgdo:Fuel",
	"lgdo:BicycleRental",
	"lgdo:BusStation",
	"lgdo:Taxi",
	"lgdo:CarRental",
	"lgdo:SkiRental",
	"lgdo:Airport",
	"lgdo:CarSharing",
);

$types_health = array(
	"lgdo:Hospital",
	"lgdo:Doctor",
	"lgdo:Doctors",
);

$types_convenience = array(
	"lgdo:Toilets",
	"lgdo:Telephone",
	"lgdo:EmergencyTelephone",
	"lgdo:Bank",
	"lgdo:ATM",
	"lgdo:Atm",
	"lgdo:Internet",
	"lgdo:InternetCafe",
	"lgdo:InternetAccess",
	"lgdo:Shower",
	"lgdo:Showers",
	"lgdo:PostBox",
	"lgdo:PostOffice",
);

?>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<title><?php echo htmlspecialchars($placename); ?> surf status</title>
	<script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.6.1/jquery.min.js"></script>
	<script type="text/javascript" src="flot/jquery.flot.min.js"></script>
	<style type="text/css">
		body {
			background-color: #364;
		}
		.shadow {
			-moz-box-shadow: 3px 3px 15px -5px #000;
		}
		.hint {
			color: #666;
			font-size: 80%;
			font-style: italic;
		}
		ul {
			padding: 0 0 0 1.5em;
		}
		table {
			text-align: left;
			margin: 0 auto;
			width: 100%;
		}
		table.spaced td {
			padding: 0.5em;
		}
		td {
			padding: 1px;
			vertical-align: top;
		}
		#wrapper {
			margin: 2em;
			border: 3px solid #132;
			-moz-border-radius: 5px;
			font-family: "Trebuchet MS";
			padding: 1.5em;
			background-color: #cdc;
			-moz-box-shadow: 5px 5px 10px #333;
			vertical-align: top;
			line-height: 20px;
		}
		a.uri {
			padding-right: 20px;
			background-image: url(images/uri.png);
			background-repeat: no-repeat;
			background-position: center right;
		}
		.barcontainer {
			position: relative;
			height: 100%;
			width: 100%;
			background-color: white;
			-moz-box-shadow: 1px 1px 4px -2px #333;
		}
		.bar {
			position: absolute;
			background-color: #bbb;
			height: 100%;
			width: 100%;
		}
		.bar.accidents { background-color: #fa0; }
		.bar.deaths { background-color: #c00; }
		.overbar {
			position: relative;
			z-index: 1;
			font-size: 80%;
			padding: 3px;
		}
		h2 {
			font-size: 120%;
			font-weight: bold;
			font-style: normal;
			margin: 1em 0 0.5em;
		}
		h3 {
			font-size: 100%;
			font-weight: bold;
			color: #676;
			text-shadow: 1px 1px 0px #fff;
			text-align: center;
		}
		table.modules {
			border-collapse: separate;
			border-spacing: 1em;
		}
		table.modules > tbody > tr > td {
			text-align: center;
			border: 2px solid #9ba;
			-moz-border-radius: 5px;
			background-color: #ded;
			padding: 0.5em;
			-moz-box-shadow: inset 5px 5px 10px -4px #fff, 3px 3px 15px -8px #000;
			font-size: 90%;
			color: #454;
		}
		table.modules > tbody > tr > td > p {
			text-shadow: 1px 1px 0px #fff;
			color: #676;
		}
		table.modules dl, table.modules ol, table.modules ul {
			text-align: left;
		}
		table.modules td h2 {
			margin-top: 0;
		}
		dt {
			font-weight: bold;
		}

		.expandlink, .collapselink {
			padding-left: 16px;
			background-repeat: no-repeat;
			background-position: center left;
		}
		.expandlink {
			background-image: url("images/bullet_toggle_plus.png");
		}
		.collapselink {
			background-image: url("images/bullet_toggle_minus.png");
		}
		.twocol {
			column-count: 2;
			column-gap: 1em;
			-moz-column-count: 2;
			-moz-column-gap: 1em;
			-webkit-column-count: 2;
			-webkit-column-gap: 1em;
		}

		#chart {
			width: 340px;
			height: 200px;
			margin: 0 auto;
		}
		#injuries, #deaths {
			width: 200px;
			height: 110px;
			margin: 0 auto;
		}
	</style>
	<script type="text/javascript">
		$(document).ready(function() {
			// slidey definition lists
			expandcollapsedl = function(e) {
				e.preventDefault();
				if ($(this).parents("dt:first").next("dd:first").is(":visible")) {
					$(this).removeClass("collapselink").addClass("expandlink");
					$(this).parents("dt:first").next("dd:first").slideUp("fast");
				} else {
					$(this).parents("dl:first").find(".collapselink").removeClass("collapselink").addClass("expandlink");
					$(this).removeClass("expandlink").addClass("collapselink");
					$(this).parents("dl:first").children("dd").not($(this).parents("dt:first").next("dd:first")).slideUp("fast");
					$(this).parents("dt:first").next("dd:first").slideDown("fast");
				}
			};
			$("dl.single > dd").hide();
			$("dl.single > dt:first-child + dd").show();
			$("dl.single > dt").each(function() {
				$(this).html("<a class=\"expandlink\" href=\"#\">" + $(this).html() + "</a>");
			});
			$("dl.single > dt:first-child .expandlink").removeClass("expandlink").addClass("collapselink");
			$("dl.single > dt a.expandlink, dl.single > dt a.collapselink").click(expandcollapsedl);

			// chart controls
			<?php if (!is_null($prevobservation)) { ?>
				$("#chart_prev").click(function(e) {
					e.preventDefault();
					newchart("<?php echo $prevobservation->uri; ?>");
				});
			<?php } else { ?>
				$("#chart_prev").hide();
			<?php } ?>
			<?php if (!is_null($nextobservation)) { ?>
				$("#chart_next").click(function(e) {
					e.preventDefault();
					newchart("<?php echo $nextobservation->uri; ?>");
				});
			<?php } else { ?>
				$("#chart_next").hide();
			<?php } ?>
			newchart = function(uri) {
				$.get("<?php echo $_SERVER["PHP_SELF"]; ?>", { chart: true, uri: uri }, function(data, textstatus, xhr) {
					$("#chart_source").attr("href", data.source).text(data.source);
					$("#chart_prev, #chart_next").unbind("click");
					if (data.next != null) {
						$("#chart_next").show().click(function(e) {
							e.preventDefault();
							newchart(data.next);
						});
					} else {
						$("#chart_next").hide();
					}
					if (data.prev != null) {
						$("#chart_prev").show().click(function(e) {
							e.preventDefault();
							newchart(data.prev);
						});
					} else {
						$("#chart_prev").hide();
					}
					chart.setData([{
						data: data.data,
						color: "#06c",
						lines: { fill: true, fillColor: "#9cf" }
					}]);
					chart.setupGrid();
					chart.draw();
				});
			};
		});
	</script>
</head>
<body>
<div id="wrapper">

<h1><?php echo htmlspecialchars($placename); ?> surf status</h1>

<?php

$modules = array();

ob_start();
?>
<h2>Sensor data</h2>
<dl>
	<dt>Sensor</dt>
	<dd>
		<?php echo $graph->resource($sensorURI)->label(); ?>
		<a class="uri" href="<?php echo htmlspecialchars($sensorURI); ?>"></a></dd>
	</dd>

	<dt>Location</dt>
	<dd>
		<dl>
			<dt>Co-ordinates</dt>
			<dd><?php echo $coords[0]; ?>, <?php echo $coords[1]; ?></dd>

			<dt>District</dt>
			<dd><?php echo htmlspecialchars($district); ?></dd>
		</dl>
	</dd>
</dl>
<?php
$modules[] = ob_get_clean();

ob_start();
?>
<h2>Sensor location map</h2>
<div style="margin: 0 auto; width: 187px; height: 256px; position: relative; background-image: url(uk.png);">
	<?php
	$width = 161;
	$height = 256;
	$bottom = 49.8825;
	$top = 58.6971;
	$left = -7.7325;
	$right = 1.761;
	$x = round($width * ($coords[1] - $left) / ($right - $left));
	$y = round($height * ($coords[0] - $bottom) / ($top - $bottom));
	?>
	<img src="pin.png" style="position: absolute; left: <?php echo $x - 7; ?>px; bottom: <?php echo $y; ?>px;">
</div>
<?php
$modules[] = ob_get_clean();

ob_start();
?>
<h2>Wave height data</h2>
<p>
	Showing wave height data from collection
	<a id="chart_source" class="uri" href="<?php echo htmlspecialchars($collection); ?>">
		<?php echo htmlspecialchars($collection->hasLabel() ? $collection->label() : $graph->shrinkURI($collection)); ?>
	</a>
</p>
<p>Unit used is <?php echo htmlspecialchars($unit->hasLabel() ? $unit->label() : $graph->shrinkURI($unit)); ?></p>
<p>
	<div id="chart"></div>
	<a id="chart_prev" href="#">&larr; Show earlier data</a>
	<a id="chart_next" href="#">Show later data &rarr;</a>
	<script type="text/javascript">
		$(function() {
			<?php
			function timestamptomilliseconds($readings) {
				$a = array();
				foreach ($readings as $reading)
					$a[] = array($reading[0] * 1000, $reading[1]);
				return $a;
			}
			echo "var heights = " . json_encode(timestamptomilliseconds($timesandheights)) . ";";
			?>
			chart = $.plot($("#chart"), [{
				data: heights,
				color: "#06c",
				lines: { fill: true, fillColor: "rgba(153, 204, 255, 0.7)" }
			}], {
				<?php if (!is_null($summary)) { ?>
					grid: { markings: [ { color: "#f00", lineWidth: 1, yaxis: { from: <?php echo $mean; ?>, to: <?php echo $mean; ?> } } ], },
				<?php } ?>
				xaxis: { mode: "time" },
			});
		});
	</script>
</p>
<?php
$modules[] = ob_get_clean();

ob_start();
?>
<?php if (is_null($summary)) { ?>
	<h2>No collection summary is available</h2>
<?php } else { ?>
	<h2>
		<?php echo htmlspecialchars($summary->label()); ?>
		<a class="uri" href="<?php echo htmlspecialchars($summary); ?>"></a>
	</h2>
	<dl>
		<dt>Created</dt>
		<dd><?php echo date("r", strtotime($summary->get("dct:created"))); ?></dd>

		<dt>For measured property</dt>
		<dd><?php $summary->get("ssne:forMeasuredProperty")->load(); echo htmlspecialchars($summary->get("ssne:forMeasuredProperty")->label()); ?></dd>

		<dt>Covers interval</dt>
		<dd>
			<?php $interval = $summary->get("ssne:coversTemporalInterval"); ?>
			<?php echo date("r", strtotime($interval->get("ssne:hasIntervalStartDate"))); ?>
			to
			<?php echo date("r", strtotime($interval->get("ssne:hasIntervalEndDate"))); ?>
		</dd>

		<dt>Maximum observation</dt>
		<dd>
			<?php $observation = $summary->get("ssne:hasMaxObservation"); ?>
			<strong><?php echo htmlspecialchars($observation->get("ssn:observationResult")->get("ssn:hasValue")->get("ssne:hasQuantityValue")); ?></strong>
			<?php $unit = $observation->get("ssn:observationResult")->get("ssn:hasValue")->get("ssne:hasQuantityUnitOfMeasure"); ?>
			<?php $unit->load(); ?>
			<?php echo htmlspecialchars($unit->hasLabel() ? $unit->label() : $graph->shrinkURI($unit)); ?>
			<br>
			Observed at <?php echo date("r", observationdate($observation)); ?>
		</dd>

		<dt>Minimum observation</dt>
		<dd>
			<?php $observation = $summary->get("ssne:hasMinObservation"); ?>
			<strong><?php echo htmlspecialchars($observation->get("ssn:observationResult")->get("ssn:hasValue")->get("ssne:hasQuantityValue")); ?></strong>
			<?php $unit = $observation->get("ssn:observationResult")->get("ssn:hasValue")->get("ssne:hasQuantityUnitOfMeasure"); ?>
			<?php $unit->load(); ?>
			<?php echo htmlspecialchars($unit->hasLabel() ? $unit->label() : $graph->shrinkURI($unit)); ?>
			<br>
			Observed at <?php echo date("r", observationdate($observation)); ?>
		</dd>

		<dt>Mean value</dt>
		<dd>
			<?php $value = $summary->get("ssne:hasMeasuredMeanValue"); ?>
			<strong><?php echo sprintf("%.3f", $mean); ?></strong>
			<?php $unit = $value->get("ssne:hasQuantityUnitOfMeasure"); ?>
			<?php $unit->load(); ?>
			<?php echo htmlspecialchars($unit->hasLabel() ? $unit->label() : $graph->shrinkURI($unit)); ?>
		</dd>
	</dl>
<?php } ?>
<?php
$modules[] = ob_get_clean();

ob_start();
?>
<h2>Nearby car parks</h2>
<table>
	<?php
	$amenities = nearbyamenities($types_parking, $coords, 5);
	$i = 0;
	foreach ($amenities as $uri => $amenity) {
		if ($i++ == 6)
			break;
		$distance = distance($amenity[1], $coords);
		?>
		<tr>
			<td>
				<?php echo htmlspecialchars($amenity[0]); ?>
				<a class="uri" href="<?php echo htmlspecialchars($uri); ?>"></a>
			</td>
			<td>
				<div class="barcontainer">
					<div class="bar" style="width: <?php echo $distance * 10; ?>%;"></div>
					<div class="overbar"><?php echo sprintf("%.01f", $distance); ?>km</div>
				</div>
			</td>
		</tr>
	<?php } ?>
</table>
<?php
$modules[] = ob_get_clean();

ob_start();
?>
<h2>Road accidents</h2>
<p>Road accident statistics for this region (<?php echo htmlspecialchars($euroRegion); ?>) compared to the national average</p>
<?php
// percentage difference strings versus national average
$injured = 100 * ($regionstats["injured"] - $average["injured"]) / $average["injured"];
$injured = ($injured >= 0 ? "+" : "") . sprintf("%.02f", $injured) . "%";
$killed = 100 * ($regionstats["killed"] - $average["killed"]) / $average["killed"];
$killed = ($killed >= 0 ? "+" : "") . sprintf("%.02f", $killed) . "%";

// needle positions for google-o-meter
$injuredneedle = max(min(($regionstats["injured"] / $average["injured"] - 0.75) * 200, 100), 0);
$killedneedle = max(min(($regionstats["killed"] / $average["killed"] - 0.75) * 200, 100), 0);
?>
<table>
	<tr>
		<td>
			<h3>Injuries</h3>
			<div id="injuries"></div>
			<script type="text/javascript">
				$(function() {
					$.plot($("#injuries"), [
						{
							data: [[1, <?php echo $average["injured"]; ?>]],
							bars: { show: true, barWidth: 0.75, align: "center" }
						},
						{
							data: [[2, <?php echo $regionstats["injured"]; ?>]],
							bars: { show: true, barWidth: 0.75, align: "center" }
						}
					], {
						xaxis: {
							min: 0.5,
							max: 2.5,
							ticks: [
								[0, ""],
								[1, "National average"],
								[2, "<?php echo htmlspecialchars($euroRegion); ?>"]
							],
							tickLength: 0
						}
					});
				});
			</script>
		</td>
		<td>
			<h3>Deaths</h3>
			<div id="deaths"></div>
			<script type="text/javascript">
				$(function() {
					$.plot($("#deaths"), [
						{
							data: [[1, <?php echo $average["killed"]; ?>]],
							bars: { show: true, barWidth: 0.75, align: "center" }
						},
						{
							data: [[2, <?php echo $regionstats["killed"]; ?>]],
							bars: { show: true, barWidth: 0.75, align: "center" }
						}
					], {
						xaxis: {
							min: 0.5,
							max: 2.5,
							ticks: [
								[0, ""],
								[1, "National average"],
								[2, "<?php echo htmlspecialchars($euroRegion); ?>"]
							],
							tickLength: 0
						}
					});
				});
			</script>
		</td>
	</tr>
</table>
<?php
$modules[] = ob_get_clean();

ob_start();
?>
<h2>Food and drink</h2>
<p>Places to get food and drink within 3km</p>
<?php
$pubbar = nearbyamenities($types_pub, $coords, 3);
$cafe = nearbyamenities($types_cafe, $coords, 3);
$restaurant = nearbyamenities($types_food, $coords, 3);
$shop = nearbyamenities($types_store, $coords, 3);
function amenitylist($amenities) {
	global $coords;
	if (is_null($amenities) || count($amenities) == 0) { ?>
		<p>Nothing found nearby</p>
		<?php
		return;
	}
	?>
	<ul>
		<?php foreach ($amenities as $uri => $amenity) { ?>
			<li>
				<?php echo htmlspecialchars($amenity[0]); ?>
				<span class="hint">(<?php echo sprintf("%.01f", distance($coords, $amenity[1])); ?>km)</span>
				<a class="uri" href="<?php echo htmlspecialchars($uri); ?>"></a>
			</li>
		<?php } ?>
	</ul>
	<?php
}
?>
<dl class="single">
	<dt><?php echo count($pubbar); ?> pubs/bars</dt>
	<dd><?php amenitylist($pubbar); ?></dd>

	<dt><?php echo count($cafe); ?> cafés</dt>
	<dd><?php amenitylist($cafe); ?></dd>

	<dt><?php echo count($restaurant); ?> restaurants/fast food/barbecues/bakeries</dt>
	<dd><?php amenitylist($restaurant); ?></dd>

	<dt><?php echo count($shop); ?> food/drink shops</dt>
	<dd><?php amenitylist($shop); ?></dd>
</dl>
<?php
$modules[] = ob_get_clean();

ob_start();
?>
<h2>Other wave height sensors</h2>
<p>Other sensors found in the triplestore which measure wave height</p>
<ul class="twocol">
	<?php foreach ($otherwavesensors as $othersensor) { ?>
		<li>
			<a href="?uri=<?php echo urlencode($othersensor["sensor"]); ?>"><?php echo htmlspecialchars(isset($othersensor["sensorname"]) ? $othersensor["sensorname"] : uriendpart($othersensor["sensor"])); ?></a>
			<a class="uri" href="<?php echo htmlspecialchars($othersensor["sensor"]); ?>"></a>
		</li>
	<?php } ?>
</ul>
<?php
$modules[] = ob_get_clean();

?>
<!--
	Yes, I'm using a table. Yes, I know it's hideous. I spent too long trying to 
	get inline-blocks to align and failing, and floats for content layout are 
	more hassle than they're worth.
-->
<table class="modules" width="100%">
	<tbody>
		<?php while (count($modules)) {
			$module1 = array_shift($modules);
			$module2 = array_shift($modules);
			?>
			<tr>
				<td><?php echo $module1; ?></td>
				<td><?php echo is_null($module2) ? "" : $module2; ?></td>
			</tr>
		<?php } ?>
	</tbody>
</table>

<hr>
<h2>Data sources</h2>
<ul>
	<li>Sensor data: <a href="http://www.channelcoast.org">Channel Coast Observatory</a></li>
	<li>Nearby place name data: <a href="http://geonames.org">Geonames</a></li>
	<li>Postcode data: <a href="http://www.uk-postcodes.com">UK Postcodes</a></li>
	<li>District, region data: <a href="http://ordnancesurvey.co.uk">Ordnance Survey</a></li>
	<li>Road accident data: <a href="http://epp.eurostat.ec.europa.eu">Eurostat</a></li>
	<li>Local amenity data: <a href="http://linkedgeodata.org">LinkedGeoData</a></li>
</ul>

</div>
</body>
</html>

<?php

function uriendpart($string) {
	return preg_replace('%.*[/#](.*?)[/#]?%', '\1', $string);
}

// return a Sparql PREFIX string, given a namespace key from the global $ns 
// array, or many such PREFIX strings for an array of such keys
function prefix($n = null) {
	global $ns;
	if (is_null($n))
		$n = array_keys($ns);
	if (!is_array($n))
		$n = array($n);
	$ret = "";
	foreach ($n as $s)
		$ret .= "PREFIX $s: <" . $ns[$s] . ">\n";
	return $ret;
}

// return results of a Sparql query
// maxage is the number of seconds old an acceptable cached result can be 
// (default one day, 0 means it must be collected newly. false means must be 
// collected newly and the result will not be stored. true means use cached 
// result however old it is)
// type is passed straight through to Arc
// missing prefixes are added
function sparqlquery($endpoint, $query, $type = "rows", $maxage = 86400/*1 day*/) {
	$cachedir = "cache/sparql/" . md5($endpoint);

	if (!is_dir($cachedir))
		mkdir($cachedir) or die("couldn't make cache directory");

	$query = addmissingprefixes($query);

	$cachefile = $cachedir . "/" . md5($query . $type);

	// collect from cache if available and recent enough
	if ($maxage === true && file_exists($cachefile) || $maxage !== false && $maxage > 0 && file_exists($cachefile) && time() < filemtime($cachefile) + $maxage)
		return unserialize(file_get_contents($cachefile));

	// cache is not to be used or cached file is out of date. query endpoint
	$config = array(
		"remote_store_endpoint" => $endpoint,
		"reader_timeout" => 120,
		"ns" => $GLOBALS["ns"],
	);
	$store = ARC2::getRemoteStore($config);
	$result = $store->query($query, $type);
	if (!empty($store->errors)) {
		foreach ($store->errors as $error)
			trigger_error("Sparql error: " . $error, E_USER_WARNING);
		return null;
	}

	// store result unless caching is switched off
	if ($maxage !== false)
		file_put_contents($cachefile, serialize($result));

	return $result;
}

// add missing PREFIX declarations to a Sparql query
function addmissingprefixes($query) {
	global $ns;

	// find existing prefix lines
	preg_match_all('%^\s*PREFIX\s+(.*?):\s*<.*>\s*$%m', $query, $matches);
	$existing = $matches[1];

	// get query without prefix declarations
	$queryonly = $query;
	if (count($existing)) {
		if (strpos($query, "\nPREFIX") !== false)
			$queryonly = substr($query, strlen($query) - strpos(strrev($query), strrev("\nPREFIX")));
		$queryonly = preg_replace('%^[^\n]*\n%', "", $queryonly);
	}

	// find namespaces used in query
	preg_match_all('%(?:^|[\s,;])([^\s:<]*):%m', $queryonly, $matches);
	$used = array_unique($matches[1]);

	// list of namespaces to add
	$add = array();
	foreach ($used as $short) {
		if (in_array($short, $existing))
			continue;
		if (!in_array($short, array_keys($ns)))
			trigger_error("Namespace '$short' used in Sparql query not found in global namespaces array", E_USER_WARNING);
		else
			$add[] = $short;
	}

	$query = prefix($add) . $query;
	return $query;
}

// query linkedgeodata.org for nearby amenities
function nearbyamenities($type, $latlon, $radius = 10) {
	global $ns;

	// upgrade $type to an array of itself if an array wasn't given
	if (!is_array($type))
		$type = array($type);

	// execute query
	$rows = sparqlquery(ENDPOINT_LINKEDGEODATA, "
		SELECT *
		WHERE {
			{ ?place a " . implode(" . } UNION { ?place a ", $type) . " . }
			?place
				a ?type ;
				geo:geometry ?placegeo ;
				rdfs:label ?placename .
			FILTER(<bif:st_intersects> (?placegeo, <bif:st_point> ($latlon[1], $latlon[0]), $radius)) .
		}
	");

	// collect results
	$results = array();
	foreach ($rows as $row) {
		$coords = parsepointstring($row['placegeo']);
		$results[$row["place"]] = array($row['placename'], $coords, distance($coords, $latlon));
	}

	// sort according to ascending distance from centre
	uasort($results, "sortbythirdelement");

	return $results;
}
function sortbythirdelement($a, $b) {
	$diff = $a[2] - $b[2];
	// usort needs integers, floats aren't good enough
	return $diff < 0 ? -1 : ($diff > 0 ? 1 : 0);
}

// parse a string
// 	POINT(longitude latitude)
// and return
// 	array(float latitude, float longitude)
function parsepointstring($string) {
	$coords = array_map("floatVal", explode(" ", preg_replace('%^.*\((.*)\)$%', '\1', $string)));
	return array_reverse($coords);
}

// return the distance in km between two array(lat, lon)
function distance($latlon1, $latlon2) {
	$angle = acos(sin(deg2rad($latlon1[0])) * sin(deg2rad($latlon2[0])) + cos(deg2rad($latlon1[0])) * cos(deg2rad($latlon2[0])) * cos(deg2rad($latlon1[1] - $latlon2[1])));
	$earthradius_km = 6372.8;
	return $earthradius_km * $angle;
}

function ok($message = null, $mimetype = "text/plain") {
	if (is_null($message))
		header("Content-Type: text/plain", true, 204);
	else {
		header("Content-Type: $mimetype", true, 200);
		echo $message;
	}
	exit;
}

// sort an array of observations by time
function sortbydate($a, $b) {
	return observationdate($a) - observationdate($b);
}

// get an observation's timestamp
function observationdate($o) {
	$time = $o->get("ssn:observationResultTime");
	if ($time->isNull())
		trigger_error("tried to get the observation date of '$o' but it doesn't have one", E_USER_ERROR);
	if (!$time->isType("time:Interval"))
		return false;
	return strtotime($time->get("time:hasBeginning"));
}

function getobservations($collection) {
	$graph = $collection->g;

	$observations = array();

	$collection->load();
	foreach ($collection->all("DUL:hasMember", "ssne:includesCollection") as $member) {
		if ($member->type()->isNull())
			$member->load();
		if ($member->type()->isNull())
			continue;

		switch ($graph->shrinkURI($member->type())) {
			case "ssn:Observation":
				// load if we don't have interesting properties
				if ($member->get("ssn:observedProperty")->isNull() || $member->get("ssn:observationResultTime")->isNull())
					$member->load();

				// skip if not wave height
				if ($member->get("ssn:observedProperty") != PROP_WINDWAVEHEIGHT)
					continue;

				// skip if it doesn't have a suitable time
				if (!$member->get("ssn:observationResultTime")->isType("time:Interval"))
					continue;

				// accept
				$observations[] = $member;
				break;
			case "ssne:ObservationCollection":
				// recurse and get observations from the subcollection
				$member->load();
				$observations = array_merge($observations, getobservations($member));
				break;
			default:
				break;
		}
	}
	return $observations;
}

?>
