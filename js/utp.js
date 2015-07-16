jQuery(document).ready(function(){
	var links = jQuery('a');
	var links_utp = jQuery('.utp a');
	
	jQuery.each( links, function( k, v ) {
		if ( links_utp.length ) {
			jQuery.each( links_utp, function( kk, vv ) {
				if ( v != vv ) {
					if ( String( jQuery(v).attr('href') ).search( window.location.host ) < 0 ) {
						jQuery( v ).attr( 'rel', 'nofollow' );
					}
				}
			});
		} else {
			if ( String( jQuery(v).attr('href') ).search( window.location.host ) < 0 ) {
				jQuery( v ).attr( 'rel', 'nofollow' );
			}
		}
	} );
	
	
});