window.confirmNavigation = function(url, msg) {
	if (confirm(msg)) {
		window.location = url;
	}
}

