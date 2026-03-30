# Image Optimization Guide for PEAS

## Current Status
- **drone.png**: 2.56 MB (2,686,870 bytes) - **NEEDS OPTIMIZATION**

## Recommended Actions

### 1. Compress drone.png
The background image is currently 2.56 MB, which is very large and will slow down page loading.

**Option A: Online Tools (Easiest)**
- Visit: https://tinypng.com/ or https://compressor.io/
- Upload `pix/drone.png`
- Download compressed version
- Replace original file
- Expected size reduction: 60-80% (should be under 500 KB)

**Option B: Using Image Editing Software**
- Open in Photoshop/GIMP
- File → Export As → PNG
- Reduce quality to 80-85%
- Or: Save as JPEG with 85% quality if transparency not needed

### 2. Create WebP Version (Modern Format)
WebP provides better compression than PNG/JPEG.

**Using Online Tools:**
- Visit: https://cloudconvert.com/png-to-webp
- Upload drone.png
- Download `drone.webp`
- Place in `pix/` folder

**Using Command Line (if ImageMagick installed):**
```bash
magick drone.png -quality 85 drone.webp
```

### 3. Update CSS for WebP Support
Once you have `drone.webp`, the CSS has been prepared to use it automatically with PNG fallback.

### 4. Lazy Loading (Future Enhancement)
Consider implementing lazy loading for images below the fold to improve initial page load time.

## Performance Impact
- **Before optimization**: 2.56 MB background image
- **After optimization**: ~400-600 KB (85% reduction)
- **Page load improvement**: 2-3 seconds faster on slow connections

## Testing After Optimization
1. Clear browser cache (Ctrl + Shift + Delete)
2. Hard refresh (Ctrl + F5)
3. Check Network tab in DevTools (F12)
4. Verify image loads and looks good
5. Test on mobile device/slow connection

## Notes
- Always keep a backup of original high-quality images
- WebP not supported in very old browsers (IE11), but PNG fallback handles this
- Consider different image sizes for mobile vs desktop (responsive images)

## Automation (Advanced)
Set up a build process with tools like:
- **Sharp** (Node.js): For automated image optimization
- **Gulp/Grunt**: Build tasks for image compression
- **Webpack**: Image optimization plugins
