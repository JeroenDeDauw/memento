<?php

/**
 *
 * A Memento TimeGate. See http://mementoweb.org
 * 
*/
class TimeGate extends SpecialPage
{


    /**
     * Constructor
     */
	function TimeGate() {
		parent::__construct( "TimeGate" );
	}



    /**
     * The init function that is called by mediawiki when loading this 
     * SpecialPage. 
     * The parameter passed to this function is the original uri. 
     * This function verifies if the article requested is valid and accessible, and 
     * fetches it's page_id, title object, etc. and passes it on to another function that 
     * fetches the memento of the requested resource. 
     *
     * @param: $par: String.
     *      The title parameter that mediawiki returns 
     *      (the url part after Special:TimeGate/)
     */
	function execute( $par ) {

		global $wgRequest, $wgOut;
		global $wgArticlePath;
		global $wgServer;
		global $wgMementoExcludeNamespaces;

		$this->setHeaders();

		$requestURL = $wgRequest->getRequestURL();
		$mementoResponse = $wgRequest->response();

		if ( !$par ) {
			$wgOut->addHTML( wfMsg( 'timegate-welcome-message' ) );
			return;
		}

		if ( $_SERVER['REQUEST_METHOD'] != 'GET' && $_SERVER['REQUEST_METHOD'] != 'HEAD' ) {
			$header = array(
					"Allow" => "GET, HEAD",
					"Vary" => "negotiate, accept-datetime"
					);
			mmSend( 405, $header, null );
			exit();
		}

		$waddress = str_replace( '$1', '', $wgArticlePath );

		// getting the title of the page from the request uri
		$title = str_replace( $wgServer . $waddress, "", $par );

		$waddress = str_replace( '/$1', '', $wgArticlePath );

		$page_namespace_id = 0;

		$objTitle =  Title::newFromText( $title );
		$page_namespace_id = $objTitle->getNamespace();

		if ( in_array( $page_namespace_id, $wgMementoExcludeNamespaces ) ) {
			$msg = wfMsgForContent( 'timegate-404-inaccessible', $par );
			mmSend( 404, null, $msg );
			exit();
		}
			
		$pg_id = $objTitle->getArticleID();

		$new_title = $objTitle->getPrefixedURL();
		$new_title = urlencode( $new_title );

		if ( $pg_id > 0 ) {
			$this->getMementoForResource( $pg_id, $new_title );
		}
		else {
			$msg = wfMsgForContent( 'timegate-404-title', $new_title );
			$header = array( "Vary" => "negotiate, accept-datetime" );
			mmSend( 404, $header, $msg );
			exit();
		}
	}


    /** 
     * Checks the validity of the requested datetime in the
     * accept-datetime header. Throws a 400 HTTP error if the 
     * requested dt is not parseable. Also sends first and last 
     * memento link headers as additional information with the errors.
     * 
     * @param: $first: associative array, not optional.
     *      url and dt of the first memento.
     * @param: $last: associative array, not optional.
     *      url and dt of the last memento.
     * @param: $Link: String, not optional.
     *       A string in link header format containing the 
     *       original, timemap, timegate, etc links. 
     */
     
	function parseRequestDateTime( $first, $last, $Link ) {

		global $wgRequest;

		// getting the datetime from the http header
		$raw_dt = $wgRequest->getHeader( "ACCEPT-DATETIME" );

		// looks for datetime enclosed in ""
		$req_dt = str_replace( '"', '', $raw_dt ); 

		// validating date time...
		$dt = wfTimestamp( TS_MW, $req_dt );

		if ( !$dt ) {
			$msg = wfMsgForContent( 'timegate-400-date', $req_dt );

			$msg .= wfMsgForContent( 'timegate-400-first-memento', $first['uri'] );
			$msg .= wfMsgForContent( 'timegate-400-last-memento', $last['uri'] );

			$header = array( "Link" => mmConstructLinkHeader( $first, $last ) . $Link );
			mmSend( 400, $header, $msg );
			exit();
		}

		return array( $dt, $raw_dt ); 
	}




    /**
     * This function retrieves the appropriate revision for a resource 
     * and builds and sends the memento headers.
     *
     * @param: $pg_id: number, not optional.
     *      The valid page_id of the requested resource. 
     * @param: $title: String, not optional.
     *      The title value of the requested resource. 
     */

	function getMementoForResource( $pg_id, $title ) {

		global $wgRequest, $wgArticlePath;

		$waddress = str_replace( '/$1', '', $wgArticlePath );

		// creating a db object to retrieve the old revision id from the db. 
		$dbr = wfGetDB( DB_SLAVE );
		$dbr->begin();

		$alt_header = '';
		$last = array(); $first = array(); $next = array(); $prev = array(); $mem = array();

		$db_details = array( 'dbr'=>$dbr, 'title'=>$title, 'waddress'=>$waddress );

		// first/last version
		$last = mmFetchMementoFor( 'last', $pg_id, null, $db_details );
		$first = mmFetchMementoFor( 'first', $pg_id, null, $db_details );


		$Link = "<" . wfExpandUrl( $waddress . "/". $title ) . ">; rel=\"original latest-version\", ";
		$Link .= "<" . wfExpandUrl( $waddress . "/" . SpecialPage::getTitleFor('TimeMap') ) . "/" . wfExpandUrl( $waddress . "/" . $title) . ">; rel=\"timemap\"; type=\"application/link-format\"";

		// checking for the occurance of the accept datetime header.
		if ( !$wgRequest->getHeader( 'ACCEPT-DATETIME' ) ) {

			if ( isset( $last['uri'] ) ) {
				$memuri = $last['uri'];
				$mem = $last;
			}
			else {
				$memuri = $first['uri'];
				$mem = $first;
			}

			$prev = mmFetchMementoFor( 'prev', $pg_id, null, $db_details );

			$header = array( 
					"Location" => $memuri,
					"Vary" => "negotiate, accept-datetime",
					"Link" => mmConstructLinkHeader( $first, $last, $mem, '', $prev ) . $Link 
					);

			$dbr->commit();
			mmSend( 302, $header, null );
			exit();
		}

		list( $dt, $raw_dt ) = $this->parseRequestDateTime( $first, $last, $Link );

		// if the requested time is earlier than the first memento, the first memento will be returned
		// if the requested time is past the last memento, or in the future, the last memento will be returned. 
		if ( $dt < wfTimestamp( TS_MW, $first['dt'] ) ) {
			$dt = wfTimestamp( TS_MW, $first['dt'] );
		}
		elseif ( $dt > wfTimestamp( TS_MW, $last['dt'] ) ) {
			$dt = wfTimestamp( TS_MW, $last['dt'] );
		}

		$prev = mmFetchMementoFor( 'prev', $pg_id, $dt, $db_details );
		$next = mmFetchMementoFor( 'next', $pg_id, $dt, $db_details );
		$mem = mmFetchMementoFor( 'memento', $pg_id, $dt, $db_details );

		$header = array( 
				"Location" => $mem['uri'],
				"Vary" => "negotiate, accept-datetime",
				"Link" => mmConstructLinkHeader( $first, $last, $mem, $next, $prev ) . $Link 
				);
		$dbr->commit();
		mmSend( 302, $header, null );
		exit();
	}
}
