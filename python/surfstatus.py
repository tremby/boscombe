#!/usr/bin/env python

import pprint
import sys
from rdflib.Graph import Graph
from rdflib import Namespace, Literal, URIRef

observationsURI = "http://localhost/semsorgrid/surfstatus/tmp/20110215.xml"

ns = dict({
	"geonames": Namespace("http://www.geonames.org/ontology#"),
	"geo": Namespace("http://www.w3.org/2003/01/geo/wgs84_pos#"),
	"foaf": Namespace("http://xmlns.com/foaf/0.1/"),
	"om": Namespace("http://www.opengis.net/om/1.0/"),
	"om2": Namespace("http://rdf.channelcoast.org/ontology/om_tmp.owl#"),
	"gml": Namespace("http://www.opengis.net/gml#"),
	"xsi": Namespace("http://schemas.opengis.net/om/1.0.0/om.xsd#"),
	"rdf": Namespace("http://www.w3.org/1999/02/22-rdf-syntax-ns#"),
	"rdfs": Namespace("http://www.w3.org/2000/01/rdf-schema#"),
	"owl": Namespace("http://www.w3.org/2002/07/owl#"),
	"pv": Namespace("http://purl.org/net/provenance/ns#"),
	"xsd": Namespace("http://www.w3.org/2001/XMLSchema#"),
	"dc": Namespace("http://purl.org/dc/elements/1.1/"),
	"lgdo": Namespace("http://linkedgeodata.org/ontology/"),
	"georss": Namespace("http://www.georss.org/georss/"),
	"eurostat": Namespace("http://www4.wiwiss.fu-berlin.de/eurostat/resource/eurostat/"),
	"postcode": Namespace("http://data.ordnancesurvey.co.uk/ontology/postcode/"),
	"admingeo": Namespace("http://data.ordnancesurvey.co.uk/ontology/admingeo/"),
	"skos": Namespace("http://www.w3.org/2004/02/skos/core#"),
	"dbpedia-owl": Namespace("http://dbpedia.org/ontology/"),
	"ssn": Namespace("http://purl.oclc.org/NET/ssnx/ssn#"),
	"DUL": Namespace("http://www.loa-cnr.it/ontologies/DUL.owl#"),
	"time": Namespace("http://www.w3.org/2006/time#"),
	"sw": Namespace("http://sweet.jpl.nasa.gov/2.1/sweetAll.owl#"),
	"id-semsorgrid": Namespace("http://id.semsorgrid.ecs.soton.ac.uk/"),
})

g = Graph()

for short, long in ns.iteritems():
	g.bind(short, long)

g.parse(observationsURI)
if len(g) < 1:
	print >> sys.stderr, "failed to load any triples from '%s'" % observationsURI
	sys.exit(1)

for s, p, o in g.triples((None, ns["rdf"]["type"], ns["ssn"]["Observation"])):
	pprint.pprint(s)
	pprint.pprint(p)
	pprint.pprint(o)

print g.query("SELECT * WHERE { ?s ?p ?o . }")
