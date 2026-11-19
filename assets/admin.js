/* Lifeboat Backup admin. No dependencies. */
( function () {
	'use strict';

	var L = window.Lifeboat || {};
	var t = L.i18n || {};

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', L.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			var v = data[ k ];
			if ( Array.isArray( v ) ) {
				v.forEach( function ( item ) {
					body.append( k + '[]', item );
				} );
			} else {
				body.append( k, v );
			}
		} );
		return fetch( L.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					throw new Error( ( j && j.data && j.data.message ) || t.error );
				}
				return j.data;
			} );
	}

	function values( form, name ) {
		return Array.prototype.map.call( form.querySelectorAll( 'input[name="' + name + '"]:checked' ), function ( i ) {
			return i.value;
		} );
	}

	/* ---------------------------------------------------------- job card */

	var card = document.getElementById( 'lb-job' );
	var form = document.getElementById( 'lb-backup-form' );
	var timer = null;

	function dismissedId() {
		try {
			return window.localStorage.getItem( 'lifeboat_dismissed_job' ) || '';
		} catch ( e ) {
			return '';
		}
	}

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( text !== undefined ) {
			n.textContent = text;
		}
		return n;
	}

	/** The finished-backup summary: what, how big, how long, and a tick per place it is stored. */
	function renderResult( job ) {
		var box = card.querySelector( '.lb-job__result' );
		box.innerHTML = '';
		var r = job.result;
		box.hidden = ! r;
		if ( ! r ) {
			return;
		}
		var facts = el( 'dl', 'lb-facts' );
		[
			[ t.included, ( r.components || [] ).join( ', ' ) ],
			[ t.size, r.size ],
			[ t.took, r.duration ],
			[ t.finishedAt, r.when ],
			[ t.encryption, r.encrypted ? t.encryptedYes : '' ],
		].forEach( function ( pair ) {
			if ( pair[ 1 ] ) {
				facts.appendChild( el( 'dt', '', pair[ 0 ] ) );
				facts.appendChild( el( 'dd', '', pair[ 1 ] ) );
			}
		} );
		box.appendChild( facts );
		var list = el( 'ul', 'lb-places' );
		( r.stored || [] ).forEach( function ( place ) {
			var li = el( 'li', 'is-ok' );
			li.appendChild( el( 'span', 'lb-tick', '✓' ) );
			li.appendChild( document.createTextNode( ' ' + place + ( r.verified ? ' — ' + t.verified : '' ) ) );
			list.appendChild( li );
		} );
		( r.failed || [] ).forEach( function ( f ) {
			var li = el( 'li', 'is-bad' );
			li.appendChild( el( 'span', 'lb-tick', '✗' ) );
			li.appendChild( document.createTextNode( ' ' + f.label + ' — ' + t.notUploaded ) );
			li.title = f.message;
			list.appendChild( li );
		} );
		if ( list.children.length ) {
			box.appendChild( list );
		}
	}

	function render( job ) {
		if ( ! card || ! job ) {
			return;
		}
		var running = job.status === 'running';
		var partial = job.status === 'done' && job.result && job.result.failed && job.result.failed.length;
		card.hidden = false;
		card.dataset.job = job.id;
		card.classList.toggle( 'is-failed', job.status === 'failed' );
		card.classList.toggle( 'is-cancelled', job.status === 'cancelled' );
		card.classList.toggle( 'is-done', job.status === 'done' && ! partial );
		card.classList.toggle( 'is-partial', !! partial );
		var restore = job.type === 'restore';
		var title;
		if ( running ) {
			title = ( restore ? t.restoring : t.backingUp ) + ' · ' + ( job.mode === 'easy' ? t.modeEasy : t.modeRush );
		} else if ( job.status === 'done' ) {
			title = partial ? t.partialTitle : ( restore ? t.restoreDone : t.backupDone );
		} else if ( job.status === 'failed' ) {
			title = restore ? t.restoreFailed : t.backupFailed;
		} else {
			title = restore ? t.restoreCancelled : t.backupCancelled;
		}
		card.querySelector( '.lb-job__icon' ).textContent = running ? '' : ( job.status === 'done' ? ( partial ? '!' : '✓' ) : '✗' );
		card.querySelector( '.lb-job__icon' ).hidden = running;
		card.querySelector( '.lb-job__title' ).textContent = title;
		card.querySelector( '.lb-progress' ).hidden = ! running;
		card.querySelector( '.lb-progress span' ).style.width = ( job.percent || 0 ) + '%';
		card.querySelector( '.lb-job__msg' ).textContent = running || job.status !== 'done' || restore ? job.message || '' : '';
		card.querySelector( '.lb-job__cancel' ).hidden = ! running;
		card.querySelector( '.lb-job__dismiss' ).hidden = running;
		card.querySelector( '.lb-job__hint' ).hidden = ! running;
		renderResult( job );
		var ul = card.querySelector( '.lb-job__warnings' );
		ul.innerHTML = '';
		( job.warnings || [] ).forEach( function ( w ) {
			ul.appendChild( el( 'li', '', w ) );
		} );
		if ( form ) {
			form.querySelector( 'button[type="submit"]' ).disabled = running;
		}
	}

	var pollFails = 0;
	function poll() {
		clearTimeout( timer );
		post( 'lifeboat_status' )
			.then( function ( job ) {
				pollFails = 0;
				render( job );
				if ( job && job.status === 'running' ) {
					timer = setTimeout( poll, 2000 );
				} else if ( job ) {
					// Reload so the backups list updates; the result card is shown again after the reload.
					setTimeout( function () {
						window.location.reload();
					}, 2500 );
				}
			} )
			.catch( function () {
				pollFails++;
				if ( pollFails < 12 ) {
					timer = setTimeout( poll, 5000 );
				} else if ( card ) {
					card.querySelector( '.lb-job__msg' ).textContent = t.lostContact;
				}
			} );
	}

	if ( card && L.job ) {
		var finishedRecently = ( L.job.finished || L.job.started ) > Date.now() / 1000 - 86400;
		if ( L.job.status === 'running' ) {
			render( L.job );
			poll();
		} else if ( finishedRecently && L.job.id !== dismissedId() ) {
			render( L.job ); // Keep the last result visible (success or failure) until dismissed.
		}
	}

	if ( card ) {
		card.querySelector( '.lb-job__dismiss' ).addEventListener( 'click', function () {
			try {
				window.localStorage.setItem( 'lifeboat_dismissed_job', card.dataset.job || '' );
			} catch ( e ) {}
			card.hidden = true;
		} );
	}

	// Notice jobs started elsewhere (schedule, after-update, another tab) while this page is open.
	if ( card && ! ( L.job && L.job.status === 'running' ) ) {
		var lastId = L.job ? L.job.id : '';
		var idle = setInterval( function () {
			if ( document.hidden ) {
				return;
			}
			post( 'lifeboat_status' ).then( function ( job ) {
				if ( job && job.status === 'running' && job.id !== lastId ) {
					clearInterval( idle );
					render( job );
					poll();
				}
			} );
		}, 15000 );
	}

	if ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var btn = form.querySelector( 'button[type="submit"]' );
			btn.disabled = true;
			post( 'lifeboat_backup_now', {
				components: values( form, 'components[]' ),
				destinations: values( form, 'destinations[]' ),
				mode: ( form.querySelector( 'input[name="mode"]:checked' ) || {} ).value || 'rush',
			} )
				.then( function ( job ) {
					render( job );
					poll();
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					window.alert( err.message );
				} );
		} );
	}

	if ( card ) {
		card.querySelector( '.lb-job__cancel' ).addEventListener( 'click', function () {
			if ( window.confirm( t.confirmCancel ) ) {
				post( 'lifeboat_cancel' ).then( function ( job ) {
					render( job );
					setTimeout( function () {
						window.location.reload();
					}, 800 );
				} );
			}
		} );
	}

	/* --------------------------------------------------------- backups list */

	var dialog = document.getElementById( 'lb-restore' );
	var labels = { db: 'Database', plugins: 'Plugins', themes: 'Themes', uploads: 'Uploads', others: 'Other content', core: 'WordPress core' };

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-act]' );
		if ( ! btn ) {
			return;
		}
		var row = btn.closest( 'tr' );
		var id = row.getAttribute( 'data-backup' );
		if ( btn.getAttribute( 'data-act' ) === 'delete' ) {
			if ( ! window.confirm( t.confirmDelete ) ) {
				return;
			}
			btn.disabled = true;
			post( 'lifeboat_delete', { backup: id } )
				.then( function () {
					row.remove();
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					window.alert( err.message );
				} );
			return;
		}
		// Restore dialog.
		var comps = JSON.parse( row.getAttribute( 'data-components' ) || '[]' );
		var sources = JSON.parse( row.getAttribute( 'data-sources' ) || '{}' );
		var parts = dialog.querySelector( '.lb-restore-parts' );
		var src = dialog.querySelector( '.lb-restore-source' );
		parts.querySelectorAll( 'label' ).forEach( function ( l ) {
			l.remove();
		} );
		src.querySelectorAll( 'label' ).forEach( function ( l ) {
			l.remove();
		} );
		comps.forEach( function ( c ) {
			var l = document.createElement( 'label' );
			l.className = 'lb-pick';
			l.innerHTML = '<input type="checkbox" name="rc" value="' + c + '" checked><span></span>';
			l.querySelector( 'span' ).textContent = labels[ c ] || c;
			parts.appendChild( l );
		} );
		Object.keys( sources ).forEach( function ( k, i ) {
			var l = document.createElement( 'label' );
			l.className = 'lb-pick';
			l.innerHTML = '<input type="radio" name="rs" value="' + k + '"' + ( i === 0 ? ' checked' : '' ) + '><span></span>';
			l.querySelector( 'span' ).textContent = sources[ k ];
			src.appendChild( l );
		} );
		dialog.querySelector( '.lb-restore-date' ).textContent = row.querySelector( 'strong' ).textContent;
		var pw = dialog.querySelector( '.lb-password' );
		pw.hidden = row.getAttribute( 'data-locked' ) !== '1';
		pw.querySelector( 'input' ).value = '';
		dialog.dataset.backup = id;
		dialog.showModal();
	} );

	if ( dialog ) {
		// Handle the button directly: the dialog "close" event is not reliable across browsers
		// when the dialog is reopened.
		dialog.querySelector( 'button[value="ok"]' ).addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var comps = values( dialog, 'rc' );
			if ( ! comps.length ) {
				window.alert( t.pickPart );
				return;
			}
			if ( ! window.confirm( t.confirmRestore ) ) {
				return;
			}
			var src = dialog.querySelector( 'input[name="rs"]:checked' );
			var payload = {
				backup: dialog.dataset.backup,
				components: comps,
				source: src ? src.value : 'local',
				keep_url: dialog.querySelector( 'input[name="keep_url"]' ).checked ? '1' : '',
			};
			var pw = dialog.querySelector( '.lb-password' );
			if ( ! pw.hidden ) {
				payload.password = pw.querySelector( 'input' ).value;
				if ( ! payload.password ) {
					window.alert( t.passwordNeeded );
					pw.querySelector( 'input' ).focus();
					return;
				}
			}
			// Keep the dialog open until the restore has really started, so a mistyped
			// password can be corrected without starting over.
			var okBtn = dialog.querySelector( 'button[value="ok"]' );
			okBtn.disabled = true;
			post( 'lifeboat_restore', payload )
				.then( function ( job ) {
					okBtn.disabled = false;
					dialog.close();
					window.scrollTo( 0, 0 );
					render( job );
					poll();
				} )
				.catch( function ( err ) {
					okBtn.disabled = false;
					window.alert( err.message );
					if ( ! pw.hidden ) {
						pw.querySelector( 'input' ).select();
					}
				} );
		} );
	}

	/* -------------------------------------------------------------- storage */

	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-test]' );
		if ( b ) {
			var out = b.closest( '.lb-dest' ).querySelector( '.lb-dest__status' );
			out.textContent = t.testing;
			post( 'lifeboat_test', { dest: b.getAttribute( 'data-test' ) } )
				.then( function ( r ) {
					out.textContent = r.message;
					out.classList.remove( 'is-error' );
				} )
				.catch( function ( err ) {
					out.textContent = err.message;
					out.classList.add( 'is-error' );
				} );
		}
		var c = e.target.closest( '[data-copy]' );
		if ( c ) {
			var input = document.getElementById( c.getAttribute( 'data-copy' ) );
			navigator.clipboard.writeText( input.value );
			c.textContent = '✓';
		}
	} );

	// Show the weekday / month-day pickers only for the matching frequency.
	var freq = document.getElementById( 'lb-schedule' );
	if ( freq ) {
		var sync = function () {
			document.querySelectorAll( '.lb-when' ).forEach( function ( el ) {
				el.hidden = ! el.classList.contains( 'lb-when--' + freq.value );
			} );
		};
		freq.addEventListener( 'change', sync );
		sync();
	}
} )();
