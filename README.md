s3hoist
=======

The easy way to get your existing site assets on to Amazon S3.

This is very much still a work in progress... but actively in development.

Setup
-----

1. `git clone https://github.com/fclukwebdev/s3hoist.git` to the web root of your website.
2. Edit the settings in process.php to include your S3 and website details.
3. Setup rewrites to redirect the assets you wish to move to s3.

API
---

s3hoist has an in URL API which can be used to pass options through to the script to help with things like generating thumbnails for images.

The following options are available:

Option | Description | Example
--- | --- | ---
w | Width in px | http://www.domain.com/images/products/w_120/product10.png will output an image scaled to 120px wide whilst maintaining its original aspect ratio.
h | Height in px | http://www.domain.com/images/products/h_150/product10.png will output an image scaled to 150px high whilst maintaining its original aspect ratio.
mw | Max Width in px | http://www.domain.com/images/products/mw_650/product10.png will output an image without any scaling unless the image is larger than the max width specified in which case a width of 650px will be set and it will be scaled.
mh | Max Height in px | http://www.domain.com/images/products/mh_650/product10.png will output an image without any scaling unless the image is larger than the max height specified in which case a height of 650px will be set and it will be scaled.
q | Quality in % | http://www.domain.com/images/products/q_80/product10.png will output an image processed at 80% of the quality of the original.
