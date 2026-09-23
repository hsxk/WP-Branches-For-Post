import apiFetch from '@wordpress/api-fetch';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { createElement, Fragment, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

const el = createElement;
const apiErrorMessage = ( error ) =>
	error?.message || __( 'WP Branches For Post could not complete the request.', 'wp-branches-for-post' );

const pathLabel = ( path ) => {
	const labels = {
		'post.post_title': __( 'Title', 'wp-branches-for-post' ),
		'post.post_content': __( 'Content', 'wp-branches-for-post' ),
		'post.post_excerpt': __( 'Excerpt', 'wp-branches-for-post' ),
		'post.menu_order': __( 'Menu order', 'wp-branches-for-post' ),
		'post.comment_status': __( 'Comment status', 'wp-branches-for-post' ),
		'post.ping_status': __( 'Ping status', 'wp-branches-for-post' ),
		'post.post_password': __( 'Password', 'wp-branches-for-post' ),
		'identity.post_status': __( 'Publication status', 'wp-branches-for-post' ),
		'identity.post_name': __( 'Slug', 'wp-branches-for-post' ),
		'identity.post_author': __( 'Author', 'wp-branches-for-post' ),
		'identity.post_parent': __( 'Parent', 'wp-branches-for-post' ),
		'identity.post_type': __( 'Post type', 'wp-branches-for-post' ),
	};
	if ( labels[ path ] ) {
		return labels[ path ];
	}
	if ( path.startsWith( 'meta.' ) ) {
		return sprintf(
			/* translators: %s: post meta key. */
			__( 'Metadata: %s', 'wp-branches-for-post' ),
			path.slice( 5 )
		);
	}
	if ( path.startsWith( 'taxonomies.' ) ) {
		return sprintf(
			/* translators: %s: taxonomy slug. */
			__( 'Taxonomy: %s', 'wp-branches-for-post' ),
			path.slice( 11 )
		);
	}
	return path;
};

const stateLabel = ( state ) => {
	const labels = {
		clean: __( 'Ready', 'wp-branches-for-post' ),
		informational: __( 'Ready · original identity changed', 'wp-branches-for-post' ),
		rebase_available: __( 'Original updated · safe to refresh branch', 'wp-branches-for-post' ),
		conflict: __( 'Needs conflict review', 'wp-branches-for-post' ),
		changed: __( 'Original changed', 'wp-branches-for-post' ),
		unknown: __( 'Legacy branch', 'wp-branches-for-post' ),
		missing: __( 'Original unavailable', 'wp-branches-for-post' ),
	};
	return labels[ state ] || state;
};

function ChangeList( { title, paths, danger = false } ) {
	if ( ! paths?.length ) {
		return null;
	}
	return el(
		'div',
		{ className: danger ? 'wbfp-review-group wbfp-review-group--danger' : 'wbfp-review-group' },
		el( 'strong', null, title ),
		el( 'ul', null, paths.map( ( path ) => el( 'li', { key: path }, pathLabel( path ) ) ) )
	);
}

function ReviewValues( { values } ) {
	if ( ! values || ! Object.keys( values ).length ) {
		return null;
	}

	const labels = {
		post_title: __( 'Title', 'wp-branches-for-post' ),
		post_excerpt: __( 'Excerpt', 'wp-branches-for-post' ),
		post_content: __( 'Content', 'wp-branches-for-post' ),
	};

	return el(
		'div',
		{ className: 'wbfp-compare' },
		el( 'strong', null, __( 'Three-way comparison', 'wp-branches-for-post' ) ),
		...Object.entries( values ).map( ( [ field, value ] ) =>
			el(
				'details',
				{ key: field, className: 'wbfp-compare__field' },
				el( 'summary', null, labels[ field ] || field ),
				el( 'div', { className: 'wbfp-compare__version' }, el( 'span', null, __( 'Base', 'wp-branches-for-post' ) ), el( 'pre', null, value.base || '—' ) ),
				el( 'div', { className: 'wbfp-compare__version' }, el( 'span', null, __( 'Original now', 'wp-branches-for-post' ) ), el( 'pre', null, value.original || '—' ) ),
				el( 'div', { className: 'wbfp-compare__version' }, el( 'span', null, __( 'Branch now', 'wp-branches-for-post' ) ), el( 'pre', null, value.branch || '—' ) )
			)
		)
	);
}

function BranchPanel() {
	const editor = useSelect( ( select ) => {
		const store = select( 'core/editor' );
		return {
			postId: store.getCurrentPostId(),
			dirty: store.isEditedPostDirty(),
			saving: store.isSavingPost(),
		};
	}, [] );
	const { savePost } = useDispatch( 'core/editor' );
	const [ status, setStatus ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ reviewOpen, setReviewOpen ] = useState( false );
	const [ confirmAction, setConfirmAction ] = useState( null );

	const refreshStatus = async () => {
		if ( ! editor.postId ) {
			return null;
		}
		const result = await apiFetch( { path: `/wbfp/v1/posts/${ editor.postId }/status` } );
		setStatus( result );
		return result;
	};

	useEffect( () => {
		if ( ! editor.postId ) {
			return;
		}
		let active = true;
		apiFetch( { path: `/wbfp/v1/posts/${ editor.postId }/status` } )
			.then( ( result ) => active && setStatus( result ) )
			.catch( ( requestError ) => active && setError( apiErrorMessage( requestError ) ) );
		return () => {
			active = false;
		};
	}, [ editor.postId ] );

	const saveBranchIfNeeded = async () => {
		if ( ! editor.dirty ) {
			return;
		}
		await savePost();
	};

	const createBranch = async () => {
		if ( editor.dirty ) {
			setError( __( 'Save or discard the current edits to the original before creating a branch. This prevents unpublished editor changes from being mistaken for the branch baseline.', 'wp-branches-for-post' ) );
			return;
		}
		setBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: `/wbfp/v1/posts/${ editor.postId }/branches`,
				method: 'POST',
			} );
			window.location.assign( result.edit_url );
		} catch ( requestError ) {
			setError( apiErrorMessage( requestError ) );
			setBusy( false );
		}
	};

	const toggleReview = async () => {
		if ( reviewOpen ) {
			setReviewOpen( false );
			return;
		}
		setBusy( true );
		setError( '' );
		try {
			if ( status?.type === 'branch' ) {
				await saveBranchIfNeeded();
				await refreshStatus();
			}
			setReviewOpen( true );
		} catch ( requestError ) {
			setError( apiErrorMessage( requestError ) );
		} finally {
			setBusy( false );
		}
	};

	const rebaseBranch = async () => {
		setBusy( true );
		setError( '' );
		try {
			await saveBranchIfNeeded();
			const result = await apiFetch( {
				path: `/wbfp/v1/branches/${ editor.postId }/rebase`,
				method: 'POST',
			} );
			setStatus( result.status );
			setReviewOpen( true );
		} catch ( requestError ) {
			setError( apiErrorMessage( requestError ) );
		} finally {
			setBusy( false );
		}
	};

	const mergeBranch = async ( force = false ) => {
		setBusy( true );
		setError( '' );
		try {
			await saveBranchIfNeeded();
			const fresh = await refreshStatus();
			const blocked = [ 'conflict', 'changed', 'unknown', 'missing' ].includes( fresh?.conflict );
			if ( blocked && ! force ) {
				setReviewOpen( true );
				setError( __( 'The review state changed after saving. Review the latest conflicts before merging.', 'wp-branches-for-post' ) );
				return;
			}
			const result = await apiFetch( {
				path: `/wbfp/v1/branches/${ editor.postId }/merge`,
				method: 'POST',
				data: { force },
			} );
			window.location.assign( result.edit_url );
		} catch ( requestError ) {
			setError( apiErrorMessage( requestError ) );
		} finally {
			setBusy( false );
		}
	};

	const discardBranch = async () => {
		setBusy( true );
		setError( '' );
		try {
			const result = await apiFetch( {
				path: `/wbfp/v1/branches/${ editor.postId }`,
				method: 'DELETE',
			} );
			window.location.assign( result.edit_url || 'edit.php' );
		} catch ( requestError ) {
			setError( apiErrorMessage( requestError ) );
			setBusy( false );
		}
	};

	let content = el( Spinner );

	if ( status?.type === 'original' ) {
		const branchCards = status.branches?.map( ( branch ) =>
			el(
				'li',
				{ key: branch.id, className: 'wbfp-branch-card' },
				el( 'div', { className: 'wbfp-branch-card__head' },
					el(
					'a',
					{ href: branch.edit_url },
					branch.title ||
						sprintf(
							/* translators: %d: branch post ID. */
							__( 'Branch #%d', 'wp-branches-for-post' ),
							branch.id
						)
				),
					el( 'span', { className: `wbfp-state wbfp-state--${ branch.conflict }` }, stateLabel( branch.conflict ) )
				),
				branch.creator && el( 'div', { className: 'wbfp-meta' }, sprintf(
					/* translators: %s: branch creator display name. */
					__( 'Created by %s', 'wp-branches-for-post' ),
					branch.creator
				) ),
				el( 'div', { className: 'wbfp-meta' }, sprintf(
					/* translators: 1: number of branch changes, 2: number of conflicts. */
					__( '%1$d branch changes · %2$d conflicts', 'wp-branches-for-post' ),
					branch.branch_changes || 0,
					branch.conflicts || 0
				) )
			)
		);
		content = el(
			Fragment,
			null,
			el( 'p', null, __( 'Edit safely in a separate draft. The published post stays unchanged until you merge the branch.', 'wp-branches-for-post' ) ),
			editor.dirty && el( Notice, { status: 'warning', isDismissible: false }, __( 'This original has unsaved editor changes. Save or discard them before creating a branch so the baseline matches the saved public post.', 'wp-branches-for-post' ) ),
			status.can_create && el( Button, { variant: 'primary', onClick: createBranch, disabled: busy || editor.dirty || editor.saving }, busy ? __( 'Creating…', 'wp-branches-for-post' ) : __( 'Create branch', 'wp-branches-for-post' ) ),
			status.branches?.length > 0 && el( 'div', { className: 'wbfp-existing-branches' }, el( 'strong', null, __( 'Existing branches', 'wp-branches-for-post' ) ), el( 'ul', null, branchCards ) )
		);
	} else if ( status?.type === 'branch' ) {
		const state = status.conflict;
		const review = status.review || {};
		const hardConflict = [ 'conflict', 'changed', 'unknown', 'missing' ].includes( state );
		const hasBranchChanges = status.legacy || ( review.branch_changes?.length || 0 ) > 0;
		const reviewPanel = reviewOpen && el(
			'div',
			{ className: 'wbfp-review' },
			el( 'div', { className: 'wbfp-review__title' }, __( 'Merge review', 'wp-branches-for-post' ) ),
			status.legacy && el( Notice, { status: 'warning', isDismissible: false }, __( 'This branch predates three-way snapshots, so detailed change comparison is unavailable. Force merge only after manually reviewing the original.', 'wp-branches-for-post' ) ),
			el( ChangeList, { title: __( 'Branch changes', 'wp-branches-for-post' ), paths: review.branch_changes } ),
			el( ChangeList, { title: __( 'Original changes since branch creation', 'wp-branches-for-post' ), paths: review.original_changes } ),
			el( ChangeList, { title: __( 'Conflicts', 'wp-branches-for-post' ), paths: review.conflicts, danger: true } ),
			el( ChangeList, { title: __( 'Original identity changes preserved by merge', 'wp-branches-for-post' ), paths: review.informational_changes } ),
			el( ReviewValues, { values: status.review_values } ),
			! status.legacy && ! review.branch_changes?.length && el( 'p', { className: 'wbfp-meta' }, __( 'No mergeable branch changes are currently detected.', 'wp-branches-for-post' ) ),
			el(
				'div',
				{ className: 'wbfp-review__actions' },
				status.can_merge && ! hardConflict && hasBranchChanges && el( Button, { variant: 'primary', onClick: () => mergeBranch( false ), disabled: busy || editor.saving }, busy ? __( 'Saving and merging…', 'wp-branches-for-post' ) : __( 'Save & merge into original', 'wp-branches-for-post' ) ),
				status.can_merge && hardConflict && status.can_force_merge && state !== 'missing' && el( Button, { variant: 'secondary', isDestructive: true, onClick: () => setConfirmAction( 'force' ), disabled: busy || editor.saving }, busy ? __( 'Saving and merging…', 'wp-branches-for-post' ) : __( 'Force merge reviewed conflicts', 'wp-branches-for-post' ) )
			)
		);

		let stateNotice = null;
		if ( state === 'rebase_available' ) {
			stateNotice = el( Notice, { status: 'info', isDismissible: false }, __( 'The original has newer, non-conflicting changes. You can update this branch first, or review and merge while preserving those original changes.', 'wp-branches-for-post' ) );
		} else if ( state === 'informational' ) {
			stateNotice = el( Notice, { status: 'info', isDismissible: false }, __( 'The original identity changed (for example status, slug, author, or parent). Those changes are preserved and are not overwritten by this branch.', 'wp-branches-for-post' ) );
		} else if ( state === 'conflict' ) {
			stateNotice = el( Notice, { status: 'warning', isDismissible: false }, __( 'The branch and original changed the same data differently. Review the conflicts before deciding whether to force merge.', 'wp-branches-for-post' ) );
		} else if ( state === 'changed' || state === 'unknown' ) {
			stateNotice = el( Notice, { status: 'warning', isDismissible: false }, __( 'This older branch cannot provide a precise three-way comparison. Review the original manually before forcing a merge.', 'wp-branches-for-post' ) );
		} else if ( state === 'missing' ) {
			stateNotice = el( Notice, { status: 'error', isDismissible: false }, __( 'The original post is unavailable. This branch cannot be merged.', 'wp-branches-for-post' ) );
		}

		content = el(
			Fragment,
			null,
			el( 'p', null,
				__( 'This draft is isolated from the public original.', 'wp-branches-for-post' ),
				status.original_edit_url && el( Fragment, null, ' ', el( 'a', { href: status.original_edit_url }, __( 'Open original', 'wp-branches-for-post' ) ) )
			),
			status.creator && el( 'p', { className: 'wbfp-meta' }, sprintf(
				/* translators: %s: branch creator display name. */
				__( 'Created by %s', 'wp-branches-for-post' ),
				status.creator
			) ),
			el( 'div', { className: `wbfp-state wbfp-state--${ state }` }, stateLabel( state ) ),
			status.synced_pattern_count > 0 && el( Notice, { status: 'warning', isDismissible: false }, sprintf(
				/* translators: %d: number of synced patterns referenced by the branch. */
				__( 'This branch references %d synced pattern(s). Editing a synced pattern is global in WordPress and is not isolated by the branch.', 'wp-branches-for-post' ),
				status.synced_pattern_count
			) ),
			stateNotice,
			el( 'div', { className: 'wbfp-preview-links' },
				status.branch_preview_url && el( 'a', { href: status.branch_preview_url, target: '_blank', rel: 'noreferrer' }, __( 'Preview branch', 'wp-branches-for-post' ) ),
				status.original_preview_url && el( 'a', { href: status.original_preview_url, target: '_blank', rel: 'noreferrer' }, __( 'View original', 'wp-branches-for-post' ) )
			),
			status.can_rebase && el( Button, { variant: 'secondary', onClick: rebaseBranch, disabled: busy || editor.saving }, busy ? __( 'Updating…', 'wp-branches-for-post' ) : __( 'Update branch from original', 'wp-branches-for-post' ) ),
			el( 'div', { className: 'wbfp-actions' },
				el( Button, { variant: reviewOpen ? 'secondary' : 'primary', onClick: toggleReview, disabled: busy }, reviewOpen ? __( 'Hide merge review', 'wp-branches-for-post' ) : __( 'Review changes', 'wp-branches-for-post' ) ),
				status.can_discard && el( Button, { variant: 'tertiary', isDestructive: true, onClick: () => setConfirmAction( 'discard' ), disabled: busy }, __( 'Discard branch', 'wp-branches-for-post' ) )
			),
			reviewPanel
		);
	}

	const confirmation =
		confirmAction &&
		el(
			Modal,
			{
				title:
					confirmAction === 'force'
						? __( 'Force merge conflicts?', 'wp-branches-for-post' )
						: __( 'Discard this branch?', 'wp-branches-for-post' ),
				onRequestClose: () => setConfirmAction( null ),
			},
			el(
				'p',
				null,
				confirmAction === 'force'
					? __( 'Force merge will prefer branch values for the listed conflicts. Non-conflicting newer work on the original is still preserved.', 'wp-branches-for-post' )
					: __( 'The branch will be moved to Trash. The public original will not be changed.', 'wp-branches-for-post' )
			),
			el(
				'div',
				{ className: 'wbfp-confirm-actions' },
				el(
					Button,
					{ variant: 'secondary', onClick: () => setConfirmAction( null ) },
					__( 'Cancel', 'wp-branches-for-post' )
				),
				el(
					Button,
					{
						variant: 'primary',
						isDestructive: true,
						onClick: () => {
							const action = confirmAction;
							setConfirmAction( null );
							if ( action === 'force' ) {
								mergeBranch( true );
							} else {
								discardBranch();
							}
						},
					},
					confirmAction === 'force'
						? __( 'Force merge', 'wp-branches-for-post' )
						: __( 'Move to Trash', 'wp-branches-for-post' )
				)
			)
		);

	return el(
		PluginDocumentSettingPanel,
		{ name: 'wbfp-branch', title: __( 'Post Branch', 'wp-branches-for-post' ) },
		error && el( Notice, { status: 'error', onRemove: () => setError( '' ) }, error ),
		content,
		confirmation
	);
}

registerPlugin( 'wp-branches-for-post', { render: BranchPanel } );
