/* Bastion Backup admin page. Plain JavaScript, no build step, no jQuery. */
( function () {
	'use strict';
	const D = window.bbkData;
	if ( ! D ) {
		return;
	}
	const $ = ( s, el ) => ( el || document ).querySelector( s );
	const $$ = ( s, el ) => Array.from( ( el || document ).querySelectorAll( s ) );
	const h = ( s ) => String( s == null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
	const size = ( b ) => ( b >= 1073741824 ? ( b / 1073741824 ).toFixed( 2 ) + ' GB' : b >= 1048576 ? ( b / 1048576 ).toFixed( 1 ) + ' MB' : Math.max( 1, Math.round( b / 1024 ) ) + ' KB' );
	const when = ( t ) => ( t ? new Date( t * 1000 ).toLocaleString() : '' );

	/** One admin-ajax call. Resolves with data, rejects with an Error carrying the server's message. */
	async function api( action, data, file ) {
		const fd = new FormData();
		fd.append( 'action', 'bbk_' + action );
		fd.append( '_ajax_nonce', D.nonce );
		Object.entries( data || {} ).forEach( ( [ k, v ] ) => {
			if ( Array.isArray( v ) ) {
				v.forEach( ( x ) => fd.append( k + '[]', x ) );
			} else if ( v !== undefined && v !== null ) {
				fd.append( k, v );
			}
		} );
		if ( file ) {
			fd.append( 'piece', file, 'piece' );
		}
		let res;
		try {
			res = await fetch( D.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } );
		} catch ( e ) {
			throw new Error( 'The server could not be reached. Check your connection.' );
		}
		let j = null;
		try {
			j = await res.json();
		} catch ( e ) {
			throw new Error( res.status >= 500 ? 'The server stopped the request (HTTP ' + res.status + '). The job carries on in the background.' : 'Unexpected answer from the server (HTTP ' + res.status + ').' );
		}
		if ( ! j || ! j.success ) {
			throw new Error( ( j && j.data && j.data.message ) || 'Something went wrong.' );
		}
		return j.data;
	}

	function flash( el, msg, ok ) {
		if ( ! el ) {
			return;
		}
		el.textContent = msg;
		el.className = 'bbk-result ' + ( ok ? 'ok' : 'err' );
	}

	function notice( msg, type ) {
		const n = document.createElement( 'div' );
		n.className = 'notice notice-' + ( type || 'error' ) + ' is-dismissible bbk-flash';
		n.setAttribute( 'role', type === 'error' ? 'alert' : 'status' );
		n.innerHTML = '<p>' + h( msg ) + '</p>';
		$( '.bbk .nav-tab-wrapper' ).before( n );
		n.scrollIntoView( { block: 'nearest' } );
		setTimeout( () => n.remove(), 12000 );
	}

	/** A small accessible dialog: a title, some fields, and a promise of the values (or null if cancelled). */
	function ask( title, text, fields, okLabel, danger ) {
		const dlg = $( '#bbk-dialog' );
		const form = $( '#bbk-dialog-form' );
		form.innerHTML = '<h2 id="bbk-dialog-title">' + h( title ) + '</h2>' + ( text ? '<p>' + h( text ) + '</p>' : '' ) +
			fields.map( ( f, i ) => '<p><label for="bbk-dlg-' + i + '">' + h( f.label ) + '</label><br><input id="bbk-dlg-' + i + '" name="' + h( f.name ) + '" type="' + h( f.type || 'text' ) + '" class="regular-text" autocomplete="' + h( f.auto || 'off' ) + '" required></p>' ).join( '' ) +
			'<p class="bbk-dlg-actions"><button value="ok" class="button button-primary' + ( danger ? ' bbk-danger-primary' : '' ) + '">' + h( okLabel ) + '</button> <button value="cancel" formnovalidate class="button">Cancel</button></p>';
		return new Promise( ( resolve ) => {
			dlg.addEventListener( 'close', function done() {
				dlg.removeEventListener( 'close', done );
				if ( dlg.returnValue !== 'ok' ) {
					return resolve( null );
				}
				const out = {};
				fields.forEach( ( f, i ) => ( out[ f.name ] = $( '#bbk-dlg-' + i ).value ) );
				resolve( out );
			} );
			dlg.returnValue = '';
			dlg.showModal();
			const first = $( 'input', form );
			if ( first ) {
				first.focus();
			}
		} );
	}

	/* ---------------- The running job ---------------- */

	let status = D.status || { id: null };
	let polling = false;

	function phaseName( s ) {
		return ( { backup: 'Backup', restore: s.params && s.params.verify_only ? 'Check' : 'Restore' } )[ s.type ] || 'Job';
	}

	/** The job's steps as a checklist: done, now, to come. */
	function steps( s ) {
		const p = s.params || {};
		let list;
		if ( s.type === 'backup' ) {
			list = [];
			if ( p.db ) {
				list.push( [ 'db', 'Database', [ 'init', 'db' ] ] );
			}
			if ( p.files ) {
				list.push( [ 'files', 'Files', [ 'scan', 'files' ] ] );
			}
			( p.dests || [] ).forEach( ( d ) => list.push( [ 'up-' + d, 'Copy to ' + ( ( D.dests[ d ] || {} ).label || d ), [ 'upload:' + d ] ] ) );
			list.push( [ 'end', 'Finish', [ 'finalize', 'retain' ] ] );
		} else if ( p.verify_only ) {
			list = [ [ 'get', 'Get the backup', [ 'init', 'acquire' ] ], [ 'check', 'Check every part', [ 'verify' ] ] ];
		} else {
			list = [ [ 'get', 'Get the backup', [ 'init', 'acquire' ] ], [ 'check', 'Check every part', [ 'verify' ] ], [ 'ok', 'Your confirmation', [ 'ready' ] ], [ 'put', 'Restore', [ 'prepare', 'restore' ] ], [ 'end', 'Finish', [ 'replace', 'swap' ] ] ];
		}
		let phase = s.phase;
		if ( phase === 'upload' ) {
			const m = ( s.message || '' ).match( /^Copying to (.+?):/ );
			const id = m && Object.keys( D.dests ).find( ( k ) => D.dests[ k ].label === m[ 1 ] );
			phase = 'upload:' + ( id || ( p.dests || [] )[ 0 ] );
		}
		const at = list.findIndex( ( x ) => x[ 2 ].includes( phase ) );
		return '<ol class="bbk-phases">' + list.map( ( x, i ) => {
			const st = s.finished && ! s.error && ! s.cancelled ? 'done' : i < at ? 'done' : i === at ? ( s.error ? 'fail' : 'now' ) : '';
			return '<li class="' + st + '"><span class="dashicons ' + ( st === 'done' ? 'dashicons-yes-alt' : st === 'now' ? 'dashicons-update' : st === 'fail' ? 'dashicons-dismiss' : 'dashicons-marker' ) + '" aria-hidden="true"></span>' + h( x[ 1 ] ) + ( st === 'now' ? '<span class="screen-reader-text"> (now)</span>' : '' ) + '</li>';
		} ).join( '' ) + '</ol>';
	}

	/** Time left, from how fast the percentage has been moving. */
	const samples = [];
	function eta( s ) {
		if ( ! s || s.finished || s.waiting || ! s.percent ) {
			samples.length = 0;
			return '';
		}
		samples.push( [ Date.now(), s.percent ] );
		while ( samples.length > 2 && samples[ 0 ][ 0 ] < Date.now() - 120000 ) {
			samples.shift();
		}
		const [ t0, p0 ] = samples[ 0 ];
		const rate = ( s.percent - p0 ) / ( ( Date.now() - t0 ) / 1000 );
		if ( samples.length < 4 || rate <= 0 ) {
			return '';
		}
		const left = ( 100 - s.percent ) / rate;
		return left < 60 ? 'less than a minute left' : left < 3600 ? 'about ' + Math.round( left / 60 ) + ' minute' + ( Math.round( left / 60 ) === 1 ? '' : 's' ) + ' left' : 'about ' + ( left / 3600 ).toFixed( 1 ) + ' hours left';
	}

	function renderJob() {
		const box = $( '#bbk-job' );
		if ( ! box ) {
			return;
		}
		const s = status;
		if ( ! s || ! s.id ) {
			box.hidden = true;
			return;
		}
		box.hidden = false;
		const running = ! s.finished;
		const cls = s.error ? 'error' : s.cancelled ? 'warning' : s.finished ? ( s.warnings && s.warnings.length ? 'warning' : 'success' ) : 'info';
		let html = '<div class="notice notice-' + cls + ' inline bbk-jobbox"><div class="bbk-jobhead"><strong>' + h( phaseName( s ) ) + '</strong> <span>' + h( s.message || '' ) + '</span></div>' + steps( s );
		if ( running && ! s.waiting ) {
			const left = eta( s );
			html += '<progress max="100" value="' + ( s.percent || 0 ) + '" aria-label="Progress">' + ( s.percent || 0 ) + '%</progress> <span class="bbk-pct">' + ( s.percent || 0 ) + '%' + ( s.bytes_total ? ' · ' + h( size( s.bytes_done || 0 ) ) + ' of ' + h( size( s.bytes_total ) ) : '' ) + ( left ? ' · ' + h( left ) : '' ) + '</span>';
			html += '<p class="description">' + h( D.i18n.bgNote ) + ( s.stale ? ' <b>No progress for a few minutes: this page is now pushing it on.</b>' : '' ) + '</p>';
		}
		if ( s.finished && s.result && s.type === 'backup' && ! s.error ) {
			const c = Object.keys( s.result.copies || {} ).filter( ( k ) => s.result.copies[ k ] ).map( ( k ) => k === 'local' ? 'this server' : ( D.dests[ k ] || {} ).label || k );
			html += '<p>' + h( s.result.name ) + ', ' + h( size( s.result.size ) ) + ' — kept on ' + h( c.join( ', ' ) ) + '.</p>';
		}
		if ( s.finished && s.result && s.type === 'restore' && s.params && s.params.verify_only && ! s.error ) {
			html += '<p>' + h( s.result.files ) + ' files, ' + h( s.result.tables ) + ' tables, ' + h( size( s.result.size ) ) + ' — every part checked.</p>';
		}
		if ( s.warnings && s.warnings.length ) {
			html += '<ul class="bbk-warn">' + s.warnings.map( ( w ) => '<li>' + h( w ) + '</li>' ).join( '' ) + '</ul>';
		}
		if ( s.log_tail && s.log_tail.length ) {
			html += '<details><summary>Log</summary><pre class="bbk-log">' + h( s.log_tail.join( '\n' ) ) + '</pre></details>';
		}
		html += '<p class="bbk-jobbtns">';
		if ( running && ! ( s.type === 'restore' && [ 'prepare', 'restore', 'replace', 'swap' ].includes( s.phase ) ) ) {
			html += '<button type="button" class="button" data-bbk="cancel">Cancel</button> ';
		}
		if ( s.finished ) {
			html += '<button type="button" class="button" data-bbk="dismiss">Dismiss</button>';
		}
		html += '</p></div>';
		box.innerHTML = html;
		if ( s.type === 'restore' && ! s.params.verify_only ) {
			renderRestore();
		}
	}

	async function poll() {
		if ( polling ) {
			return;
		}
		polling = true;
		let fails = 0;
		while ( status && status.id && ! status.finished && ! status.waiting ) {
			try {
				status = await api( 'tick' );
				fails = 0;
			} catch ( e ) {
				fails++;
				status.message = e.message;
				if ( fails > 20 ) {
					break;
				}
			}
			renderJob();
			await new Promise( ( r ) => setTimeout( r, 800 ) );
		}
		polling = false;
		renderJob();
		if ( status && status.finished && status.type === 'backup' ) {
			loadList();
		}
	}

	function started( s ) {
		status = s;
		renderJob();
		poll();
		$( '#bbk-job' ).scrollIntoView( { block: 'nearest' } );
	}

	/* ---------------- Backups tab ---------------- */

	function keyFile( key, id ) {
		const text = [
			'BASTION BACKUP KEY for ' + location.host, '', key, '',
			'Key ' + id + ', made ' + new Date().toLocaleString() + '.',
			'It opens every backup made with this key, and nothing else can. Keep it in your organisation\'s password',
			'manager or printed in a safe. Never email it, and never keep it on the web server. If it is lost, the',
			'backups cannot be opened by anyone.', '',
			'To restore: Bastion Backup > Restore (this site), or installer.php with the .bbk file (a new server).',
		].join( '\r\n' );
		const a = document.createElement( 'a' );
		a.href = URL.createObjectURL( new Blob( [ text ], { type: 'text/plain' } ) );
		a.download = 'bastion-backup-key-' + id + '.txt';
		a.click();
		URL.revokeObjectURL( a.href );
	}

	function showKey( k ) {
		const box = $( '#bbk-key-made' );
		box.innerHTML = '<p><b>Your backup key (key ' + h( k.id ) + ').</b> It is shown only this once. The site keeps no copy, so without it no backup can be opened, by anyone.</p>' +
			'<p><code class="bbk-keytext">' + h( k.text ) + '</code></p>' +
			'<p><button type="button" class="button" id="bbk-k-copy">Copy</button> <button type="button" class="button" id="bbk-k-file">Download as a text file</button></p>' +
			'<p>Keep it in your organisation\'s password manager, or printed in a safe, with a second copy held by another senior person. Never email it or save it on the web server.</p>' +
			'<p><label><input type="checkbox" id="bbk-k-kept"> I have stored the key safely</label></p>' +
			'<p><button type="button" class="button button-primary" id="bbk-k-done" disabled>Done, hide the key</button></p>';
		box.hidden = false;
		$( '#bbk-key-form' ).hidden = true;
		$( '#bbk-k-copy' ).onclick = () => navigator.clipboard.writeText( k.text ).then( () => notice( D.i18n.copied, 'success' ) );
		$( '#bbk-k-file' ).onclick = () => keyFile( k.text, k.id );
		$( '#bbk-k-kept' ).onchange = ( e ) => ( $( '#bbk-k-done' ).disabled = ! e.target.checked );
		$( '#bbk-k-done' ).onclick = () => location.reload();
		box.scrollIntoView( { block: 'nearest' } );
	}

	const rows = new Map();

	async function loadList() {
		const tb = $( '#bbk-rows' );
		if ( ! tb ) {
			return;
		}
		rows.clear();
		const wheres = [ 'local' ].concat( Object.keys( D.dests ).filter( ( k ) => D.dests[ k ].configured ) );
		const errors = [];
		await Promise.all( wheres.map( async ( w ) => {
			try {
				const r = await api( 'list', { where: w } );
				r.items.forEach( ( it ) => {
					const row = rows.get( it.name ) || { name: it.name, size: it.size, time: it.time, kid: '', at: {} };
					row.at[ w ] = it.id;
					row.kid = row.kid || it.kid || '';
					row.size = row.size || it.size;
					row.time = row.time || it.time;
					rows.set( it.name, row );
				} );
			} catch ( e ) {
				errors.push( ( w === 'local' ? 'This server' : D.dests[ w ].label ) + ': ' + e.message );
			}
		} ) );
		const list = Array.from( rows.values() ).sort( ( a, b ) => b.name.slice( -26 ).localeCompare( a.name.slice( -26 ) ) );
		let html = list.map( ( r ) => {
			const icon = ( w ) => ( w === 'local' ? '<span class="dashicons dashicons-admin-site-alt3 bbk-brand" aria-hidden="true"></span>' : w === 'fileserver' ? '<span class="dashicons dashicons-networking bbk-brand" aria-hidden="true"></span>' : '<img class="bbk-brand" src="' + h( D.dests[ w ].icon ) + '" width="16" height="16" alt="">' );
			const where = Object.keys( r.at ).map( ( w ) => '<span class="bbk-badge">' + icon( w ) + h( w === 'local' ? 'This server' : D.dests[ w ].label ) + '</span>' ).join( ' ' );
			const first = r.at.local ? 'local' : Object.keys( r.at )[ 0 ];
			return '<tr data-name="' + h( r.name ) + '"><td><b>' + h( when( r.time ) ) + '</b><div class="bbk-small">' + h( r.name ) + '</div></td><td class="num">' + h( size( r.size ) ) + '</td><td>' + where + '</td><td>' + h( r.kid ) + '</td>' +
				'<td class="bbk-checkcell"><button type="button" class="button button-small" data-bbk="check" data-where="' + h( first ) + '">Check with the key</button></td>' +
				'<td class="bbk-rowbtns">' + ( r.at.local ? '<a class="button button-small" href="' + h( D.download + '&name=' + encodeURIComponent( r.name ) ) + '">Download</a> ' : '' ) +
				'<button type="button" class="button button-small" data-bbk="restore-this" data-where="' + h( first ) + '">Restore…</button> ' +
				Object.keys( r.at ).map( ( w ) => '<button type="button" class="button button-small bbk-danger" data-bbk="delete" data-where="' + h( w ) + '" aria-label="Delete from ' + h( w === 'local' ? 'this server' : D.dests[ w ].label ) + '">Delete' + ( Object.keys( r.at ).length > 1 ? ' (' + h( w === 'local' ? 'here' : D.dests[ w ].label ) + ')' : '' ) + '</button>' ).join( ' ' ) + '</td></tr>';
		} ).join( '' );
		if ( ! list.length ) {
			html = '<tr><td colspan="6">No backups yet.</td></tr>';
		}
		if ( errors.length ) {
			html += '<tr><td colspan="6" class="bbk-err">' + errors.map( h ).join( '<br>' ) + '</td></tr>';
		}
		tb.innerHTML = html;
	}

	function rowOf( btn ) {
		return rows.get( btn.closest( 'tr' ).dataset.name );
	}

	/* ---------------- Restore tab ---------------- */

	function chosen() {
		try {
			return JSON.parse( sessionStorage.getItem( 'bbk-source' ) || 'null' );
		} catch ( e ) {
			return null;
		}
	}

	function showChosen() {
		const c = chosen();
		const el = $( '#bbk-r-chosen' );
		if ( ! el || ! c ) {
			return;
		}
		el.innerHTML = 'Backup: <b>' + h( c.name ) + '</b> (' + h( c.label ) + ')';
		$( '#bbk-r-check button' ).disabled = false;
	}

	function renderRestore() {
		const s = status;
		const pv = $( '#bbk-r-preview' );
		const cf = $( '#bbk-r-confirm' );
		if ( ! pv || ! cf ) {
			return;
		}
		const ready = s && s.type === 'restore' && s.phase === 'ready' && s.waiting && ! s.finished;
		pv.hidden = ! ready;
		cf.hidden = ! ready;
		if ( ! ready || ! s.manifest ) {
			return;
		}
		const m = s.manifest;
		const r = s.result || {};
		const moving = m.site_url && m.site_url.replace( /\/$/, '' ) !== D.siteUrl;
		pv.innerHTML = '<table class="widefat striped bbk-manifest"><tbody>' +
			[ [ 'Made', when( m.created ) + ( m.by ? ' by ' + m.by : '' ) ], [ 'From the site', m.site_url ], [ 'WordPress / PHP', ( m.wp || '?' ) + ' / ' + ( m.php || '?' ) ],
				[ 'Holds', ( m.components && m.components.db ? r.tables + ' database tables' : 'no database' ) + ', ' + ( m.components && m.components.files ? r.files + ' files' : 'no files' ) ],
				[ 'Size', size( r.size || 0 ) ], [ 'Key', m.kid || '' ] ].map( ( [ k, v ] ) => '<tr><th scope="row">' + h( k ) + '</th><td>' + h( v ) + '</td></tr>' ).join( '' ) +
			'</tbody></table>' + ( moving ? '<p class="notice notice-info inline"><span>The backup is from another address. Links and settings in the database will be changed to this site\'s address.</span></p>' : '' );
		if ( m.components ) {
			$( '#bbk-r-db' ).checked = !! m.components.db;
			$( '#bbk-r-db' ).disabled = ! m.components.db;
			if ( ! $( '#bbk-r-files' ).hasAttribute( 'data-dfm' ) ) {
				$( '#bbk-r-files' ).checked = !! m.components.files && ! $( '#bbk-r-files' ).disabled;
			}
		}
	}

	async function upload() {
		const input = $( '#bbk-r-file' );
		const f = input && input.files && input.files[ 0 ];
		const bar = $( '#bbk-r-upbar' );
		const msg = $( '#bbk-r-upmsg' );
		if ( ! f ) {
			return flash( msg, 'Choose a .bbk file first.', false );
		}
		try {
			const b = await api( 'upload_begin', { name: f.name, size: f.size } );
			bar.hidden = false;
			let off = 0;
			let tries = 0;
			while ( off < f.size ) {
				const piece = f.slice( off, Math.min( f.size, off + D.piece ) );
				try {
					const r = await api( 'upload_piece', { id: b.id, offset: off }, piece );
					off = r.have;
					tries = 0;
				} catch ( e ) {
					if ( ++tries > 5 ) {
						throw e;
					}
					await new Promise( ( r ) => setTimeout( r, 2000 * tries ) );
				}
				bar.value = Math.floor( ( 100 * off ) / f.size );
				msg.textContent = size( off ) + ' of ' + size( f.size );
			}
			sessionStorage.setItem( 'bbk-source', JSON.stringify( { where: 'upload', upload: b.id, name: f.name, label: 'uploaded from your computer' } ) );
			flash( msg, 'Uploaded. Now check it with the key.', true );
			showChosen();
		} catch ( e ) {
			flash( msg, e.message, false );
		}
	}

	/* ---------------- Clicks ---------------- */

	document.addEventListener( 'click', async ( ev ) => {
		const b = ev.target.closest( '[data-bbk]' );
		if ( ! b ) {
			return;
		}
		const what = b.dataset.bbk;
		const form = b.closest( '[data-dest]' ) || b.closest( 'form' );
		const out = form ? $( '.bbk-result', form ) : null;
		try {
			if ( what === 'cancel' ) {
				status = await api( 'cancel' );
				renderJob();
			} else if ( what === 'dismiss' ) {
				status = await api( 'dismiss' );
				renderJob();
				renderRestore();
			} else if ( what === 'backup' ) {
				b.disabled = true;
				started( await api( 'backup', { db: $( '#bbk-c-db' ).checked ? '1' : '0', files: $( '#bbk-c-files' ).checked ? '1' : '0', dests: $$( '.bbk-c-dest:checked' ).map( ( x ) => x.value ) } ) );
				b.disabled = false;
			} else if ( what === 'refresh' ) {
				loadList();
			} else if ( what === 'key-replace' ) {
				$( '#bbk-key-form' ).hidden = false;
				b.hidden = true;
				$( '#bbk-key-pw' ).focus();
			} else if ( what === 'key-cancel' ) {
				$( '#bbk-key-form' ).hidden = true;
				$( '[data-bbk="key-replace"]' ).hidden = false;
			} else if ( what === 'check' ) {
				const r = rowOf( b );
				const v = await ask( 'Check this backup', 'Every part is read and checked with the key. Nothing on the site changes.', [ { name: 'key', label: 'Backup key (BBK-…)', type: 'password' } ], 'Check' );
				if ( v ) {
					started( await api( 'check', { where: b.dataset.where, id: r.at[ b.dataset.where ], name: r.name, key: v.key } ) );
				}
			} else if ( what === 'restore-this' ) {
				const r = rowOf( b );
				const w = b.dataset.where;
				sessionStorage.setItem( 'bbk-source', JSON.stringify( { where: w, id: r.at[ w ], name: r.name, label: w === 'local' ? 'on this server' : 'on ' + D.dests[ w ].label } ) );
				location.href = D.restoreUrl;
			} else if ( what === 'delete' ) {
				const r = rowOf( b );
				const w = b.dataset.where;
				const v = await ask( 'Delete this backup?', r.name + ' will be deleted from ' + ( w === 'local' ? 'this server' : D.dests[ w ].label ) + '. It cannot be brought back.', [ { name: 'password', label: 'Your WordPress password', type: 'password', auto: 'current-password' } ], 'Delete', true );
				if ( v ) {
					await api( 'delete', { where: w, id: r.at[ w ], name: r.name, password: v.password } );
					notice( 'Deleted.', 'success' );
					loadList();
				}
			} else if ( what === 'upload' ) {
				upload();
			} else if ( what === 'copy' ) {
				const i = b.parentElement.querySelector( 'input' );
				await navigator.clipboard.writeText( i.value );
				notice( D.i18n.copied, 'success' );
			} else if ( what === 'connect' ) {
				const r = await api( 'dest_connect', { dest: form.dataset.dest } );
				location.href = r.url;
			} else if ( what === 'connect-shared' ) {
				b.disabled = true;
				const r = await api( 'dest_connect', { dest: form.dataset.dest, shared: '1' } );
				location.href = r.url;
			} else if ( what === 'device' ) {
				await connectDevice( b, form, out );
			} else if ( what === 'test' ) {
				flash( out, D.i18n.working, true );
				const r = await api( 'dest_test', { dest: form.dataset.dest } );
				flash( out, r.message, true );
			} else if ( what === 'disconnect' ) {
				if ( window.confirm( 'Remove this connection? Backups already there are left as they are.' ) ) {
					await api( 'dest_disconnect', { dest: form.dataset.dest } );
					location.reload();
				}
			}
		} catch ( e ) {
			b.disabled = false;
			if ( out ) {
				flash( out, e.message, false );
			} else {
				notice( e.message );
			}
		}
	} );

	/* ---------------- "Connect": sign in at Google or Microsoft with a code ---------------- */

	async function connectDevice( btn, box, out ) {
		const dest = box.dataset.dest;
		const label = ( D.dests[ dest ] || {} ).label || dest;
		btn.disabled = true;
		const r = await api( 'dest_device_start', { dest } );
		const dv = $( '.bbk-device', box );
		dv.innerHTML = '<div class="notice notice-info inline"><ol class="bbk-steps">' +
			'<li>Open <a href="' + h( r.url ) + '" target="_blank" rel="noopener noreferrer">' + h( r.url.replace( /^https:\/\//, '' ) ) + '</a> and sign in with the account that should keep the backups.</li>' +
			'<li>Enter this code: <code class="bbk-keytext bbk-devcode">' + h( r.code ) + '</code> <button type="button" class="button button-small" id="bbk-dev-copy">Copy</button></li>' +
			'<li>Allow access. This page notices by itself.</li></ol>' +
			'<p class="bbk-devwait"><span class="spinner is-active"></span> Waiting for you to allow access…</p></div>';
		dv.hidden = false;
		btn.hidden = true;
		$( '#bbk-dev-copy' ).onclick = () => navigator.clipboard.writeText( r.code ).then( () => notice( D.i18n.copied, 'success' ) );
		// Copied for the person, so they only paste it on the sign-in page that opens.
		try {
			await navigator.clipboard.writeText( r.code );
		} catch ( e ) {
			/* The page may not be allowed to write the clipboard: the code is shown to copy by hand. */
		}
		window.open( r.url, '_blank', 'noopener' );
		const until = Date.now() + r.expires_in * 1000;
		try {
			while ( Date.now() < until ) {
				await new Promise( ( z ) => setTimeout( z, r.interval * 1000 ) );
				const p = await api( 'dest_device_poll', { dest } );
				if ( p.state === 'connected' ) {
					dv.innerHTML = '<div class="notice notice-success inline"><p>' + h( label ) + ' is connected' + ( p.account ? ' as ' + h( p.account ) : '' ) + '.</p></div>';
					setTimeout( () => location.reload(), 900 );
					return;
				}
			}
			throw new Error( 'The sign-in code has expired. Press Connect again.' );
		} catch ( e ) {
			dv.hidden = true;
			btn.hidden = false;
			btn.disabled = false;
			throw e;
		}
	}

	/* ---------------- Forms ---------------- */

	document.addEventListener( 'submit', async ( ev ) => {
		const f = ev.target;
		if ( f.id === 'bbk-dialog-form' ) {
			return;
		}
		if ( ! f.closest( '.bbk' ) ) {
			return;
		}
		ev.preventDefault();
		const btn = $( 'button.button-primary, input[type=submit]', f );
		if ( btn ) {
			btn.disabled = true;
		}
		const out = $( '.bbk-result', f );
		try {
			if ( f.id === 'bbk-key-form' ) {
				showKey( await api( 'key', { password: $( '#bbk-key-pw' ).value } ) );
			} else if ( f.id === 'bbk-r-check' ) {
				const c = chosen();
				if ( ! c ) {
					throw new Error( 'Choose the backup first.' );
				}
				started( await api( 'restore_check', Object.assign( {}, c, { key: $( '#bbk-r-key' ).value } ) ) );
				$( '#bbk-r-key' ).value = '';
			} else if ( f.id === 'bbk-r-confirm' ) {
				started( await api( 'restore_confirm', { password: $( '#bbk-r-pw' ).value, confirm: $( '#bbk-r-word' ).value, db: $( '#bbk-r-db' ).checked ? '1' : '0', files: $( '#bbk-r-files' ).checked ? '1' : '0' } ) );
				$( '#bbk-r-pw' ).value = '';
				sessionStorage.removeItem( 'bbk-source' );
			} else if ( f.id === 'bbk-storage-form' ) {
				const r = await api( 'storage', { path: $( '#bbk-st-path' ).value, password: $( '#bbk-st-pw' ).value } );
				flash( out, r.message, true );
				setTimeout( () => location.reload(), 1500 );
			} else if ( f.id === 'bbk-inst-form' ) {
				const r = await api( 'installer', { password: $( '#bbk-inst-pw' ).value } );
				const box = $( '#bbk-inst-made' );
				box.innerHTML = '<p><b>The installer\'s access key</b> (shown only this once): <code class="bbk-keytext">' + h( r.access ) + '</code></p><p><a class="button button-primary" href="' + h( r.url ) + '">Download installer.php</a> <button type="button" class="button" id="bbk-i-copy">Copy the access key</button></p><p class="description">The download works once. Keep the access key with the backup key; the installer will not open without it.</p>';
				box.hidden = false;
				f.hidden = true;
				$( '#bbk-i-copy' ).onclick = () => navigator.clipboard.writeText( r.access ).then( () => notice( D.i18n.copied, 'success' ) );
			} else if ( f.classList.contains( 'bbk-dest-form' ) ) {
				const data = { dest: f.dataset.dest };
				$$( 'input[name], select[name], textarea[name]', f ).forEach( ( i ) => {
					if ( i.type === 'radio' ) {
						if ( i.checked ) {
							data[ i.name ] = i.value;
						}
					} else {
						data[ i.name ] = i.value;
					}
				} );
				const r = await api( 'dest_save', data );
				flash( out, r.message + ( r.configured ? '' : ' Not complete yet.' ), true );
				$$( 'input[type=password], textarea[name=private_key]', f ).forEach( ( i ) => ( i.value = '' ) );
				setTimeout( () => location.reload(), 700 );
			} else if ( f.classList.contains( 'bbk-settings' ) ) {
				const data = { section: f.dataset.section };
				$$( 'input[name], select[name], textarea[name]', f ).forEach( ( i ) => {
					if ( i.name.endsWith( '[]' ) ) {
						const k = i.name.slice( 0, -2 );
						data[ k ] = data[ k ] || [];
						if ( i.checked ) {
							data[ k ].push( i.value );
						}
					} else if ( i.type === 'checkbox' ) {
						data[ i.name ] = i.checked ? '1' : '';
					} else {
						data[ i.name ] = i.value;
					}
				} );
				const r = await api( 'settings', data );
				notice( r.message, 'success' );
			}
		} catch ( e ) {
			if ( out ) {
				flash( out, e.message, false );
			} else {
				notice( e.message );
			}
		} finally {
			if ( btn ) {
				btn.disabled = false;
			}
		}
	} );

	/* File server form: show the fields that apply. */
	function fsFields() {
		const f = $( '.bbk-dest-form[data-dest="fileserver"]' );
		if ( ! f ) {
			return;
		}
		const sftp = $( '[name=protocol]', f ).value === 'sftp';
		const key = ( $( '[name=auth]:checked', f ) || {} ).value === 'key';
		$$( '.bbk-sftp-only', f ).forEach( ( r ) => ( r.hidden = ! sftp ) );
		$$( '.bbk-auth-key', f ).forEach( ( r ) => ( r.hidden = ! key ) );
		$$( '.bbk-auth-password', f ).forEach( ( r ) => ( r.hidden = key ) );
		const keyRadio = $( '[name=auth][value=key]', f );
		keyRadio.disabled = ! sftp;
		if ( ! sftp && key ) {
			$( '[name=auth][value=password]', f ).checked = true;
			fsFields();
		}
	}
	document.addEventListener( 'change', ( e ) => {
		if ( e.target.closest( '.bbk-dest-form[data-dest="fileserver"]' ) ) {
			fsFields();
		}
		if ( e.target.id === 'bbk-r-word' || e.target.id === 'bbk-r-pw' ) {
			return;
		}
	} );

	/* ---------------- Start ---------------- */
	fsFields();
	renderJob();
	loadList();
	showChosen();
	if ( $( '#bbk-r-files' ) && $( '#bbk-r-files' ).disabled ) {
		$( '#bbk-r-files' ).setAttribute( 'data-dfm', '1' );
	}
	if ( status && status.id && ! status.finished && ! status.waiting ) {
		poll();
	}
}() );
