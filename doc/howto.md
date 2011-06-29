Coding a mashup: Boscombe surf status
=====================================

As an example of using the HLAPI this section describes how a "surf status" 
mashup application was built.

The purpose of this mashup is to take wave height data from the HLAPI for one or 
more areas, plot this data on a graph and at the same time pick up related 
information from other sources such as a map showing the location and lists of 
nearby amenities.

Scripting language and libraries
--------------------------------

This example uses the [PHP][php] scripting language. For Sparql queries and RDF 
manipulation it uses the [Arc2][arc2] library and, for ease of coding and 
readability, [Graphite][graphite]. The [Flot][flot] Javascript library (a 
[Jquery][jquery] plugin) is used for charts and the [Google Static Maps 
API][gsmapi] and [Openlayers][openlayers] for mapping.

Another useful tool is an RDF browser such as the [Q&D RDF Browser][qdbrowser].

[php]: http://php.net/
[arc2]: http://arc.semsol.org/
[graphite]: http://graphite.ecs.soton.ac.uk/
[flot]: http://code.google.com/p/flot/
[jquery]: http://jquery.com/
[gsmapi]: http://code.google.com/apis/maps/documentation/staticmaps/
[openlayers]: http://openlayers.org/
[qdbrowser]: http://graphite.ecs.soton.ac.uk/browser/

First we load in the Arc2 and Graphite libraries and set up Graphite with a list 
of namespaces for coding simplicity.

	require_once "arc/ARC2.php";
	require_once "Graphite.php";
	$graph = new Graphite();
	$graph->ns("id-semsorgrid", "http://id.semsorgrid.ecs.soton.ac.uk/");
	$graph->ns("ssn", "http://purl.oclc.org/NET/ssnx/ssn#");
	$graph->ns("ssne", "http://www.semsorgrid4env.eu/ontologies/SsnExtension.owl#");
	$graph->ns("DUL", "http://www.loa-cnr.it/ontologies/DUL.owl#");
	$graph->ns("time", "http://www.w3.org/2006/time#");

This continues for other useful namespace prefixes. The `id-semsorgrid` prefix 
is added for further code brevity.

Displaying a map of all wave height sensors
-------------------------------------------

One of the observation serializations available from the CCO deployment of the 
HLAPI is a GeoJSON format. This serialization, which shows the locations of all 
wave height readings made in a particular time frame, can be rendered by various 
mapping engines including Openlayers.

The markup to display the map, given the path to an OpenJSON file, is very 
simple and fully documented by Openlayers.

Depending on how the HLAPI is configured the OpenJSON representation of wave 
height readings for a particular hour may be at

	http://geojson.semsorgrid.ecs.soton.ac.uk/observations/cco/Hs/20110215/00

Given this URL a map such as the following may be generated.

![Openlayers map screenshot](openlayersscreenshot.png)

Getting the day's wave height readings and the sensor metadata
--------------------------------------------------------------

In the case of the CCO deployment, the current day's wave height readings for 
the Boscombe sensor are identified by

	http://id.semsorgrid.ecs.soton.ac.uk/observations/cco/boscombe/Hs/latest

We can direct Graphite to load the resources into a graph -- Graphite and the 
HLAPI will automatically negotiate a content type which can be used. We're using 
the namespace we defined above for brevity.

	$graph->load("id-semsorgrid:observations/cco/boscombe/Hs/latest");

Graphite allows the graph to be rendered directly as HTML to quickly visualize 
what is available, the same can be achieved by using a dedicated RDF browser.

	echo $graph->dump();

The beginning of the output is something like the following:

	id-semsorgrid:observations/cco/boscombe/Hs/20110215
		-> rdf:type -> ssne:ObservationCollection
		-> DUL:hasMember -> id-semsorgrid:observations/cco/boscombe/Hs/20110215#000000,
			id-semsorgrid:observations/cco/boscombe/Hs/20110215#003000,
			id-semsorgrid:observations/cco/boscombe/Hs/20110215#010000

	id-semsorgrid:observations/cco/boscombe/Hs/20110215#000000
		-> rdf:type -> ssn:Observation
		-> DUL:directlyPrecedes -> id-semsorgrid:observations/cco/boscombe/Hs/20110215#003000
		-> DUL:isMemberOf -> id-semsorgrid:observations/cco/Hs/20110215
		-> DUL:directlyFollows -> id-semsorgrid:observations/cco/boscombe/Hs/20110214#233000
		-> ssn:observedProperty -> http://marinemetadata.org/2005/08/ndbc_waves#Wind_Wave_Height
		-> ssn:featureOfInterest -> http://www.eionet.europa.eu/gemet/concept?cp=7495
		-> ssn:observedBy -> id-semsorgrid:sensors/cco/boscombe
		-> ssn:observationResult -> _:arce2d5b1
		-> ssn:observationResultTime -> _arce2d5b3
		<- is DUL:hasMember of <- id-semsorgrid:observations/cco/boscombe/Hs/20110215
		<- is DUL:directlyFollows of <- id-semsorgrid:observations/cco/boscombe/Hs/20110215#003000
		<- is DUL:directlyPrecedes of <- id-semsorgrid:observations/cco/boscombe/Hs/20110214#233000

The bnodes (blank nodes -- non-literal nodes not identified by URIs) are also 
shown and their IDs can be traced to see which properties are available on each 
node.

A lot of useful information such as the sensor's coordinates is attached to the 
sensor's URI, which is linked from each `ssn:Observation` node. It's easy to get 
the URI, simply by getting `ssn:Observation` nodes and then collecting the first 
found `ssn:observedBy` property of any of them. It's important to handle the 
case where there are not yet any results.

	$sensor = $graph->allOfType("ssn:Observation")->get("ssn:observedBy")->distinct()->current();
	if ($sensor->isNull())
		die("No results yet today");
	$sensorURI = $sensor->uri;

To get the sensor's coordinates we ask Graphite to dereference the sensor's URI 
and load its triples, then traverse the expanded graph to fetch the required 
values. The traversals here can once again be visualized by first dumping the 
graph or exploring the graph in any RDF browser.

	$graph->load($sensorURI);
	$location = $graph->resource($sensorURI)->get("ssn:hasDeployment")->get("ssn:deployedOnPlatform")->get("sw:hasLocation");
	$coords = array(
		floatVal((string) $location->get("sw:coordinate2")->get("sw:hasNumericValue")),
		floatVal((string) $location->get("sw:coordinate1")->get("sw:hasNumericValue")),
	);

To collect all wave height observations we query the graph for all nodes of type 
`ssn:Observation` and skip over those whose `ssn:observedProperty` property is 
not that which we are looking for (just in case we have other observation types 
in our graph).

We can then sort those observations by time using a helper function. This helper 
function compares the `time:Interval` nodes linked to by each observation's 
`ssn:observationResultTime` property.

Again, to see how the traversals are built up it's easiest to inspect the graph 
visually.

	// collect observations
	$observations = array();
	foreach ($graph->resource($collectionURI)->all("DUL:hasMember")->allOfType("ssn:Observation") as $observationNode) {
		if ($observationNode->get("ssn:observedProperty") != "http://marinemetadata.org/2005/08/ndbc_waves#Wind_Wave_Height")
			continue;
		if (!$observationNode->get("ssn:observationResultTime")->isType("time:Interval"))
			continue;
		$observations[] = $observationNode;
	}
	usort($observations, "sortbydate");

	// sort an array of observations by time
	function sortbydate($a, $b) {
		return strtotime($a->get("ssn:observationResultTime")->get("time:hasBeginning"))
			- strtotime($b->get("ssn:observationResultTime")->get("time:hasBeginning"));
	}

Visualizing the data
--------------------

Now that we have the readings in order we can produce a chart of the wave 
heights. Explaining the snippet below is out of the scope of this document, but 
it uses Flot to produce a line graph of wave height against time.

	<div id="chart"></div>
	<a id="chart_prev" href="#">&larr; Show earlier data</a>
	<a id="chart_next" href="#">Show later data &rarr;</a>
	<script type="text/javascript">
		$(function() {
			<?php
			$timesandheights = array();
			foreach ($observations as $observationNode) {
				$timeNode = $observationNode->get("ssn:observationResultTime");
				$time = strtotime($timeNode->get("time:hasBeginning"));
				$timesandheights[] = array($time * 1000, floatVal((string) $observationNode->get("ssn:observationResult")->get("ssn:hasValue")->get("ssne:hasQuantityValue")));
			}

			echo "var heights = " . json_encode($timesandheights) . ";";
			?>
			chart = $.plot($("#chart"), [{
				data: heights,
				color: "#06c",
				lines: { fill: true, fillColor: "#9cf" }
			}], {
				xaxis: { mode: "time" }
			});
		});
	</script>

It's easy to show a map with the sensor's position highlighted, too: the 
following uses the Google Static Maps API to do this.

	echo '<img src="http://maps.google.com/maps/api/staticmap?size=300x200&center=' . $coords[0] . ',' . $coords[1] . '&zoom=8&maptype=hybrid&sensor=false&markers=' . $coords[0] . ',' . $coords[1] . '">';

Fetching related data from other data sources
---------------------------------------------

We can get the name of a nearby place and the nearest post code from the web 
services provided by [Geonames](http://www.geonames.org/) and [UK 
Postcodes](http://www.uk-postcodes.com/). Geonames returns XML and UK Postcodes 
can return RDF, both of which are easy to parse. Again, explaining how the 
external API calls work isn't in the scope of this document.

	// get nearby place name
	$placenameXML = simplexml_load_file("http://ws.geonames.org/findNearbyPlaceName?lat={$coords[0]}&lng={$coords[1]}");
	$placename = array_shift($placenameXML->xpath('/geonames/geoname[1]/name[1]'));

	// get nearby postcode
	$pcgraph = new Graphite();
	$pcgraph->load("http://www.uk-postcodes.com/latlng/{$coords[0]},{$coords[1]}.rdf");
	foreach ($pcgraph->allSubjects() as $subject)
		$subject->loadSameAs();
	$postcode = $pcgraph->allOfType("postcode:PostcodeUnit")->current();

The postcode is used in the surf status mashup to fetch the British region name 
from Ordnance Survey, which in turn is used to fetch population and traffic 
accident data from Eurostat.

Data is also collected from [Linked Geodata](http://linkedgeodata.org/) to get 
the whereabouts of nearby facilities. For instance, to get parking facilities 
within five kilometres of the sensor, its Sparql endpoint is queried as follows.

	$store = ARC2::getRemoteStore(array("remote_store_endpoint" => "http://linkedgeodata.org/sparql/"));
	$rows = $store->query("
		PREFIX lgdo: <http://linkedgeodata.org/ontology/>
		PREFIX geo: <http://www.w3.org/2003/01/geo/wgs84_pos#>
		PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
		SELECT * WHERE {
			{ ?place a lgdo:Parking . }
			UNION { ?place a lgdo:MotorcycleParking . }
			UNION { ?place a lgdo:BicycleParking . }
			?place
				a ?type ;
				geo:geometry ?placegeo ;
				rdfs:label ?placename .
			FILTER(<bif:st_intersects> (?placegeo, <bif:st_point> ($coords[1], $coords[0]), 5)) .
		}
	", "rows");

The returned results include the coordinates of each parking facility 
(`placegeo`), from which the distance to the sensor can be calculated.

Similar queries can be used to get data on other types of nearby amenities -- 
the surf status mashup also locates nearby pubs, cafés and shops.

Finished mashup
---------------

The finished mashup, once styled, looks something like the screenshot shown. 
This version doesn't use Google Maps and when it was taken there were only two 
wave height sensors being tracked.

![Finished mashup screenshot](screenshot.png)
