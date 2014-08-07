Wordpress Weather Alerts
========================

Pulls weather alerts for your area from the National Weather Searvice in Realtime and displays them on your WordPress Site


Install
-------

1. Clone into your plugins directory and activate in the WordPress Admin UI.
2. Click the link in the alert message that pops up in WordPress telling you that the Weather Alerts URL isn't set
3. Find the ATOM feed for your area here: http://alerts.weather.gov/   ONLY USE THE ATOM FEED! Ii should look like this: http://alerts.weather.gov/cap/wwaatmget.php?x=MAC017&y=0
4. Paste the ATOM feed url into the Admin Page
5. fiddle with other settings to your content :-)
6. Done!

P.S. Front End Support not available out of the box yet... You need to edit your theme and use 
`$myalerts = apply_filters('alerts', array())` 
and then loop through the alerts and render them using something like what I have already for the Admin Notices


```php
<?php foreach ( $myalerts as $alert ) : ?>
	<div class="error">
		<p>
			<b>Weather Alert: </b> 
			<?php _e( $alert['title'], 'weather_alerts' ); ?>
			<a href="<?php echo esc_url( $alert['url'] ); ?>"><?php _e( $alert['readmore-text'], 'weather_alerts' ); ?> </a>
		</p>
	</div>
<?php endforeach; ?>
```

Admin UI
--------

![Weather Alerts UI](http://cdn.ht.gs/i/weather-alerts.png)

![Weather Alert Admin Notice](http://cdn.ht.gs/i/weather-alerts-active.png)



Notes
-----

* In WordPress Multisite Weather Alerts restricts the UI Settings Editor to Super admins and it can be found in the Network Admin Menu
* The Weather Alerts Menu Item is always below the Settings Group
* If you don't specify any alert filters then all alerts will be shown


TODO
----

1. Frontend UI out of the box
