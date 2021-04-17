// Initialize modules
// Importing specific gulp API functions lets us write them below as series() instead of gulp.series()
const { src, dest, watch, series, parallel } = require('gulp');
// Importing all the Gulp-related packages we want to use
const sourcemaps = require('gulp-sourcemaps');
const sass = require('gulp-sass');
const concat = require('gulp-concat');
const uglify = require('gulp-uglify');
const postcss = require('gulp-postcss');
const autoprefixer = require('autoprefixer');
const cssnano = require('cssnano');
var replace = require('gulp-replace');
const merge = require('merge-stream');


// File paths
const files = {
  scssPath: 'app/scss/**/*.scss',
  jsPath: 'app/js/**/*.js'
}

// Sass task: compiles the style.scss file into style.css
function scssTask() {
  return src(files.scssPath)
    .pipe(sourcemaps.init()) // initialize sourcemaps first
    .pipe(sass()) // compile SCSS to CSS
    .pipe(postcss([autoprefixer(), cssnano()])) // PostCSS plugins
    .pipe(sourcemaps.write('.')) // write sourcemaps file in current directory
    .pipe(dest('css')
    ); // put final CSS in dist folder
}

// JS task: concatenates and uglifies JS files to script.js
function jsTask() {
  return src([
    'node_modules/isotope-layout/dist/isotope.pkgd.min.js',
    'node_modules/bootstrap/dist/js/bootstrap.bundle.min.js',
    files.jsPath
    //,'!' + 'includes/js/jquery.min.js', // to exclude any specific files
  ])
    .pipe(concat('all.js'))
    .pipe(uglify())
    .pipe(dest('js')
    );
}

function copyLibrariesTask() {
  var fortawesome_css = src('node_modules/@fortawesome/fontawesome-free/css/**/*')
    .pipe(dest('libraries/fontawesome-free/css'));

  var fortawesome_webfonts = src('node_modules/@fortawesome/fontawesome-free/webfonts/**/*')
    .pipe(dest('libraries/fontawesome-free/webfonts'));

  return merge(fortawesome_css, fortawesome_webfonts);
}

// Cachebust
// function cacheBustTask() {
//   var cbString = new Date().getTime();
//   return src(['index.html'])
//     .pipe(replace(/cb=\d+/g, 'cb=' + cbString))
//     .pipe(dest('.'));
// }

// Watch task: watch SCSS and JS files for changes
// If any change, run scss and js tasks simultaneously
function watchTask() {
  watch([files.scssPath, files.jsPath],
    { interval: 1000, usePolling: true }, //Makes docker work
    series(
      parallel(scssTask, jsTask),
      //cacheBustTask
    )
  );
}

// Export the default Gulp task so it can be run
// Runs the scss and js tasks simultaneously
// then runs cacheBust, then watch task
exports.default = series(
  parallel(scssTask, jsTask, copyLibrariesTask),
  //cacheBustTask,
  watchTask
);
