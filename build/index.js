( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.editor || ! wp.apiFetch ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useEffect = wp.element.useEffect;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var Button = wp.components.Button;
	var Notice = wp.components.Notice;
	var Spinner = wp.components.Spinner;
	var Panel = wp.editor.PluginDocumentSettingPanel;
	var apiFetch = wp.apiFetch;

	function errorMessage( error ) {
		return ( error && error.message ) || __( 'WP Branches For Post could not complete the request.', 'wp-branches-for-post' );
	}

	function BranchPanel() {
		var postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );
		var statusState = useState( null );
		var status = statusState[ 0 ];
		var setStatus = statusState[ 1 ];
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];
		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];

		useEffect( function () {
			if ( ! postId ) {
				return undefined;
			}
			var active = true;
			apiFetch( { path: '/wbfp/v1/posts/' + postId + '/status' } )
				.then( function ( result ) {
					if ( active ) {
						setStatus( result );
					}
				} )
				.catch( function ( requestError ) {
					if ( active ) {
						setError( errorMessage( requestError ) );
					}
				} );
			return function () {
				active = false;
			};
		}, [ postId ] );

		function createBranch() {
			setBusy( true );
			setError( '' );
			apiFetch( { path: '/wbfp/v1/posts/' + postId + '/branches', method: 'POST' } )
				.then( function ( result ) { window.location.assign( result.edit_url ); } )
				.catch( function ( requestError ) { setError( errorMessage( requestError ) ); setBusy( false ); } );
		}

		function mergeBranch( force ) {
			setBusy( true );
			setError( '' );
			apiFetch( { path: '/wbfp/v1/branches/' + postId + '/merge', method: 'POST', data: { force: !! force } } )
				.then( function ( result ) { window.location.assign( result.edit_url ); } )
				.catch( function ( requestError ) { setError( errorMessage( requestError ) ); setBusy( false ); } );
		}

		function discardBranch() {
			if ( ! window.confirm( __( 'Move this branch to Trash? The public original will not be changed.', 'wp-branches-for-post' ) ) ) {
				return;
			}
			setBusy( true );
			setError( '' );
			apiFetch( { path: '/wbfp/v1/branches/' + postId, method: 'DELETE' } )
				.then( function ( result ) { window.location.assign( result.edit_url || 'edit.php' ); } )
				.catch( function ( requestError ) { setError( errorMessage( requestError ) ); setBusy( false ); } );
		}

		var content = el( Spinner );

		if ( status && status.type === 'original' ) {
			var branchItems = status.branches && status.branches.length ? el(
				'div', { className: 'wbfp-existing-branches' },
				el( 'strong', null, __( 'Existing branches', 'wp-branches-for-post' ) ),
				el( 'ul', null, status.branches.map( function ( item ) {
					return el( 'li', { key: item.id },
						el( 'a', { href: item.edit_url }, sprintf( __( 'Branch #%d', 'wp-branches-for-post' ), item.id ) ),
						item.conflict !== 'clean' ? el( 'span', { className: 'wbfp-conflict-dot', title: __( 'Original changed', 'wp-branches-for-post' ) }, '!' ) : null
					);
				} ) )
			) : null;

			content = el( Fragment, null,
				el( 'p', null, __( 'Edit safely in a separate draft. The published post stays unchanged until you merge the branch.', 'wp-branches-for-post' ) ),
				status.can_create ? el( Button, { variant: 'primary', onClick: createBranch, disabled: busy }, busy ? __( 'Creating…', 'wp-branches-for-post' ) : __( 'Create branch', 'wp-branches-for-post' ) ) : null,
				branchItems
			);
		} else if ( status && status.type === 'branch' ) {
			var hasConflict = status.conflict !== 'clean';
			var warning = hasConflict ? el( Notice, { status: 'warning', isDismissible: false },
				status.conflict === 'unknown'
					? __( 'This is a legacy branch with no baseline snapshot. Review the original before merging.', 'wp-branches-for-post' )
					: __( 'The original changed after this branch was created. A normal merge is blocked to prevent overwriting newer work.', 'wp-branches-for-post' )
			) : null;

			content = el( Fragment, null,
				el( 'p', null,
					__( 'This draft is isolated from the public original.', 'wp-branches-for-post' ),
					status.original_edit_url ? el( Fragment, null, ' ', el( 'a', { href: status.original_edit_url }, __( 'Open original', 'wp-branches-for-post' ) ) ) : null
				),
				status.creator ? el( 'p', { className: 'wbfp-meta' }, sprintf( __( 'Created by %s', 'wp-branches-for-post' ), status.creator ) ) : null,
				warning,
				el( 'div', { className: 'wbfp-actions' },
					status.can_merge && ! hasConflict ? el( Button, { variant: 'primary', onClick: function () { mergeBranch( false ); }, disabled: busy }, busy ? __( 'Merging…', 'wp-branches-for-post' ) : __( 'Merge into original', 'wp-branches-for-post' ) ) : null,
					status.can_merge && hasConflict ? el( Button, { variant: 'secondary', isDestructive: true, onClick: function () { mergeBranch( true ); }, disabled: busy }, busy ? __( 'Merging…', 'wp-branches-for-post' ) : __( 'Force merge after review', 'wp-branches-for-post' ) ) : null,
					status.can_discard ? el( Button, { variant: 'tertiary', isDestructive: true, onClick: discardBranch, disabled: busy }, __( 'Discard branch', 'wp-branches-for-post' ) ) : null
				)
			);
		}

		return el( Panel, { name: 'wbfp-branch', title: __( 'Post Branch', 'wp-branches-for-post' ) },
			error ? el( Notice, { status: 'error', onRemove: function () { setError( '' ); } }, error ) : null,
			content
		);
	}

	wp.plugins.registerPlugin( 'wp-branches-for-post', { render: BranchPanel } );
} )( window.wp );
