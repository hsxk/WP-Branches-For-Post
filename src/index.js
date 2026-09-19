import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

const apiErrorMessage = ( error ) =>
	error?.message || __( 'WP Branches For Post could not complete the request.', 'wp-branches-for-post' );

function BranchPanel() {
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
	const [ status, setStatus ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		if ( ! postId ) {
			return;
		}
		let active = true;
		apiFetch( { path: `/wbfp/v1/posts/${ postId }/status` } )
			.then( ( result ) => active && setStatus( result ) )
			.catch( ( requestError ) => active && setError( apiErrorMessage( requestError ) ) );
		return () => {
			active = false;
		};
	}, [ postId ] );

	const createBranch = async () => {
		setBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: `/wbfp/v1/posts/${ postId }/branches`,
				method: 'POST',
			} );
			window.location.assign( result.edit_url );
		} catch ( requestError ) {
			setError( apiErrorMessage( requestError ) );
			setBusy( false );
		}
	};

	const mergeBranch = async ( force = false ) => {
		setBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: `/wbfp/v1/branches/${ postId }/merge`,
				method: 'POST',
				data: { force },
			} );
			window.location.assign( result.edit_url );
		} catch ( requestError ) {
			setError( apiErrorMessage( requestError ) );
			setBusy( false );
		}
	};

	const discardBranch = async () => {
		if ( ! window.confirm( __( 'Move this branch to Trash? The public original will not be changed.', 'wp-branches-for-post' ) ) ) {
			return;
		}
		setBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: `/wbfp/v1/branches/${ postId }`,
				method: 'DELETE',
			} );
			window.location.assign( result.edit_url || 'edit.php' );
		} catch ( requestError ) {
			setError( apiErrorMessage( requestError ) );
			setBusy( false );
		}
	};

	let content = <Spinner />;

	if ( status?.type === 'original' ) {
		content = (
			<>
				<p>{ __( 'Edit safely in a separate draft. The published post stays unchanged until you merge the branch.', 'wp-branches-for-post' ) }</p>
				{ status.can_create && (
					<Button variant="primary" onClick={ createBranch } disabled={ busy }>
						{ busy ? __( 'Creating…', 'wp-branches-for-post' ) : __( 'Create branch', 'wp-branches-for-post' ) }
					</Button>
				) }
				{ status.branches?.length > 0 && (
					<div className="wbfp-existing-branches">
						<strong>{ __( 'Existing branches', 'wp-branches-for-post' ) }</strong>
						<ul>
							{ status.branches.map( ( branch ) => (
								<li key={ branch.id }>
									<a href={ branch.edit_url }>{ sprintf( __( 'Branch #%d', 'wp-branches-for-post' ), branch.id ) }</a>
									{ branch.conflict !== 'clean' && <span className="wbfp-conflict-dot" title={ __( 'Original changed', 'wp-branches-for-post' ) }>!</span> }
								</li>
							) ) }
						</ul>
					</div>
				) }
			</>
		);
	} else if ( status?.type === 'branch' ) {
		const hasConflict = status.conflict !== 'clean';
		content = (
			<>
				<p>
					{ __( 'This draft is isolated from the public original.', 'wp-branches-for-post' ) }
					{ status.original_edit_url && <> <a href={ status.original_edit_url }>{ __( 'Open original', 'wp-branches-for-post' ) }</a></> }
				</p>
				{ status.creator && <p className="wbfp-meta">{ sprintf( __( 'Created by %s', 'wp-branches-for-post' ), status.creator ) }</p> }
				{ hasConflict && (
					<Notice status="warning" isDismissible={ false }>
						{ status.conflict === 'unknown'
							? __( 'This is a legacy branch with no baseline snapshot. Review the original before merging.', 'wp-branches-for-post' )
							: __( 'The original changed after this branch was created. A normal merge is blocked to prevent overwriting newer work.', 'wp-branches-for-post' ) }
					</Notice>
				) }
				<div className="wbfp-actions">
					{ status.can_merge && ! hasConflict && (
						<Button variant="primary" onClick={ () => mergeBranch( false ) } disabled={ busy }>
							{ busy ? __( 'Merging…', 'wp-branches-for-post' ) : __( 'Merge into original', 'wp-branches-for-post' ) }
						</Button>
					) }
					{ status.can_merge && hasConflict && (
						<Button variant="secondary" isDestructive onClick={ () => mergeBranch( true ) } disabled={ busy }>
							{ busy ? __( 'Merging…', 'wp-branches-for-post' ) : __( 'Force merge after review', 'wp-branches-for-post' ) }
						</Button>
					) }
					{ status.can_discard && (
						<Button variant="tertiary" isDestructive onClick={ discardBranch } disabled={ busy }>
							{ __( 'Discard branch', 'wp-branches-for-post' ) }
						</Button>
					) }
				</div>
			</>
		);
	}

	return (
		<PluginDocumentSettingPanel name="wbfp-branch" title={ __( 'Post Branch', 'wp-branches-for-post' ) }>
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ content }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'wp-branches-for-post', { render: BranchPanel } );
