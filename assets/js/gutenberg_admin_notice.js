   ( function( wp ) {
    wp.data.dispatch('core/notices').createinfoNotice(
        'info', // Can be one of: success, info, warning, error.
        'Error Message.', // Text string to display.
        {
            isDismissible: true, // Whether the user can dismiss the notice.
            // Any actions the user can perform.
            actions: [
                {
                    url: '#',
                    label: 'View post'
                }
            ]
        }
    );
} )( window.wp );
