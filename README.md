s3hoist
=======

The easy way to get your existing site assets on to Amazon S3.

This is very much still a work in progress... but actively in development.

Setup
-----

<ol>
  <li>`git clone https://github.com/fclukwebdev/s3hoist.git` to the web root of your website.</li>
  <li>Setup rewrites to redirect the assets you wish to move to s3</li>
</ol>

API
---

s3hoist has an in URL API which can be used to pass options through to the script to help with things like generating thumbnails for images.

The following options are available:

Option | Description | Example
--- | --- | ---
w | width | http://www.domain.com/images/products/w_120/product10.png will output an image 120px wide whilst maintaining its original aspect ratio.
h | height | http://www.domain.com/images/products/h_150/product10.png will output an image 150px high whilst maintaining its original aspect ratio.

