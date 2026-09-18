/* global cpcfAdmin */
( function () {
	'use strict';

	var config = window.cpcfAdmin || {};
	var i18n = config.i18n || {};

	/**
	 * Tabs: switch panels without reloading and remember the choice in the URL.
	 */
	function initTabs() {
		var links = document.querySelectorAll( '.cpcf-tabs a' );
		var panels = document.querySelectorAll( '.cpcf-panel' );
		var submit = document.getElementById( 'cpcf-submit' );

		if ( ! links.length ) {
			return;
		}

		function activate( id ) {
			links.forEach( function ( link ) {
				link.classList.toggle( 'nav-tab-active', link.getAttribute( 'data-tab' ) === id );
			} );

			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-panel' ) !== id;
			} );

			if ( submit ) {
				submit.hidden = 'tools' === id;
			}

			if ( window.history && window.history.replaceState ) {
				var url = new URL( window.location.href );
				url.searchParams.set( 'tab', id );
				window.history.replaceState( null, '', url.toString() );
			}
		}

		links.forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				activate( link.getAttribute( 'data-tab' ) );
			} );
		} );
	}

	/**
	 * Verify the token and populate the zone list.
	 */
	function initVerify() {
		var button = document.getElementById( 'cpcf-verify' );
		var status = document.getElementById( 'cpcf-verify-status' );
		var tokenInput = document.getElementById( 'cpcf-api-token' );
		var zoneSelect = document.getElementById( 'cpcf-zone' );

		if ( ! button || ! status ) {
			return;
		}

		function setStatus( message, type ) {
			status.textContent = message || '';
			status.className = 'description cpcf-verify-status' + ( type ? ' cpcf-verify-' + type : '' );
		}

		function pickZone( zones ) {
			var siteHost = ( config.siteHost || '' ).toLowerCase();
			var current = config.zoneId || '';
			var chosen = '';

			zones.forEach( function ( zone ) {
				if ( zone.id === current ) {
					chosen = zone.id;
				}
			} );

			if ( ! chosen && siteHost ) {
				zones.forEach( function ( zone ) {
					var name = zone.name.toLowerCase();

					if ( siteHost === name || siteHost.slice( -( name.length + 1 ) ) === '.' + name ) {
						if ( ! chosen || name.length > chosen.length ) {
							chosen = zone.id;
						}
					}
				} );
			}

			return chosen;
		}

		function fillZones( zones ) {
			if ( ! zoneSelect ) {
				return;
			}

			var chosen = pickZone( zones );

			zoneSelect.innerHTML = '';

			var placeholder = document.createElement( 'option' );
			placeholder.value = '';
			placeholder.textContent = i18n.selectZone || '';
			zoneSelect.appendChild( placeholder );

			zones.forEach( function ( zone ) {
				var option = document.createElement( 'option' );
				var label = zone.name;

				if ( zone.plan ) {
					label += ' (' + zone.plan + ')';
				}

				if ( zone.status && 'active' !== zone.status ) {
					label += ' [' + zone.status + ']';
				}

				option.value = zone.id + '|' + zone.name;
				option.textContent = label;
				option.selected = zone.id === chosen;
				zoneSelect.appendChild( option );
			} );

			zoneSelect.disabled = false;
		}

		button.addEventListener( 'click', function () {
			var data = new window.FormData();

			data.append( 'action', 'cpcf_verify_token' );
			data.append( 'nonce', config.nonce || '' );
			data.append( 'token', tokenInput ? tokenInput.value.trim() : '' );

			button.disabled = true;
			setStatus( i18n.verifying || '', 'pending' );

			window
				.fetch( config.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data,
				} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					button.disabled = false;

					if ( ! json || ! json.success ) {
						setStatus( json && json.data && json.data.message ? json.data.message : i18n.requestError, 'error' );
						return;
					}

					var zones = json.data.zones || [];

					if ( ! zones.length ) {
						setStatus( json.data.message || i18n.noZones, 'warning' );
						return;
					}

					fillZones( zones );
					setStatus( ( json.data.message || '' ) + ' ' + ( i18n.saveReminder || '' ), 'ok' );
				} )
				.catch( function () {
					button.disabled = false;
					setStatus( i18n.requestError || '', 'error' );
				} );
		} );
	}

	/**
	 * Dependency rules repeater.
	 */
	function initRules() {
		var table = document.getElementById( 'cpcf-rules' );
		var addButton = document.getElementById( 'cpcf-add-rule' );
		var template = document.getElementById( 'cpcf-rule-template' );

		if ( ! table || ! addButton || ! template ) {
			return;
		}

		var body = table.querySelector( 'tbody' );

		function nextIndex() {
			var max = -1;

			body.querySelectorAll( 'select[name]' ).forEach( function ( select ) {
				var match = select.getAttribute( 'name' ).match( /\[rules\]\[(\d+)\]/ );

				if ( match ) {
					max = Math.max( max, parseInt( match[ 1 ], 10 ) );
				}
			} );

			return max + 1;
		}

		addButton.addEventListener( 'click', function () {
			var html = template.innerHTML.replace( /__INDEX__/g, String( nextIndex() ) );
			var wrapper = document.createElement( 'tbody' );

			wrapper.innerHTML = html.trim();
			body.appendChild( wrapper.firstElementChild );
		} );

		table.addEventListener( 'click', function ( event ) {
			var target = event.target;

			if ( target && target.classList.contains( 'cpcf-remove-rule' ) ) {
				var row = target.closest( 'tr' );

				if ( row ) {
					row.parentNode.removeChild( row );
				}
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initTabs();
		initVerify();
		initRules();
	} );
} )();
