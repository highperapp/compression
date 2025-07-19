use std::ffi::{CStr, CString};
use std::os::raw::c_char;
use std::ptr;
use std::slice;
use brotli::{CompressorReader, DecompressorReader};
use std::io::{Read, Write};

/// High-performance Brotli compression library for HighPer Framework
/// 
/// Performance targets:
/// - Compression: 20-30% better than gzip
/// - Speed: 3-5x faster than PHP brotli extension
/// - Memory: 50% less memory usage than pure PHP
/// - Throughput: 100MB/s+ compression on modern hardware

#[no_mangle]
pub extern "C" fn compress(
    input_data: *const u8,
    input_len: usize,
    quality: u32,
    window_size: u32,
    output_len: *mut usize,
) -> *mut u8 {
    if input_data.is_null() || output_len.is_null() {
        return ptr::null_mut();
    }

    let input_slice = unsafe { slice::from_raw_parts(input_data, input_len) };
    
    // Validate parameters
    let quality = quality.min(11); // Brotli quality is 0-11
    let window_size = window_size.clamp(10, 24); // Window size is 10-24

    match compress_internal(input_slice, quality, window_size) {
        Ok(compressed) => {
            unsafe {
                *output_len = compressed.len();
            }
            
            // Allocate memory for output
            let output_ptr = unsafe { libc::malloc(compressed.len()) as *mut u8 };
            if output_ptr.is_null() {
                return ptr::null_mut();
            }
            
            // Copy compressed data
            unsafe {
                ptr::copy_nonoverlapping(compressed.as_ptr(), output_ptr, compressed.len());
            }
            
            output_ptr
        }
        Err(_) => ptr::null_mut(),
    }
}

#[no_mangle]
pub extern "C" fn decompress(
    input_data: *const u8,
    input_len: usize,
    output_len: *mut usize,
) -> *mut u8 {
    if input_data.is_null() || output_len.is_null() {
        return ptr::null_mut();
    }

    let input_slice = unsafe { slice::from_raw_parts(input_data, input_len) };
    
    match decompress_internal(input_slice) {
        Ok(decompressed) => {
            unsafe {
                *output_len = decompressed.len();
            }
            
            // Allocate memory for output
            let output_ptr = unsafe { libc::malloc(decompressed.len()) as *mut u8 };
            if output_ptr.is_null() {
                return ptr::null_mut();
            }
            
            // Copy decompressed data
            unsafe {
                ptr::copy_nonoverlapping(decompressed.as_ptr(), output_ptr, decompressed.len());
            }
            
            output_ptr
        }
        Err(_) => ptr::null_mut(),
    }
}

#[no_mangle]
pub extern "C" fn compress_string(
    input: *const c_char,
    quality: u32,
    window_size: u32,
    output_len: *mut usize,
) -> *mut u8 {
    if input.is_null() || output_len.is_null() {
        return ptr::null_mut();
    }

    let input_str = unsafe {
        match CStr::from_ptr(input).to_str() {
            Ok(s) => s,
            Err(_) => return ptr::null_mut(),
        }
    };

    compress(
        input_str.as_ptr(),
        input_str.len(),
        quality,
        window_size,
        output_len,
    )
}

#[no_mangle]
pub extern "C" fn decompress_to_string(
    input_data: *const u8,
    input_len: usize,
) -> *mut c_char {
    if input_data.is_null() {
        return ptr::null_mut();
    }

    let input_slice = unsafe { slice::from_raw_parts(input_data, input_len) };
    
    match decompress_internal(input_slice) {
        Ok(decompressed) => {
            match String::from_utf8(decompressed) {
                Ok(s) => {
                    match CString::new(s) {
                        Ok(c_str) => c_str.into_raw(),
                        Err(_) => ptr::null_mut(),
                    }
                }
                Err(_) => ptr::null_mut(),
            }
        }
        Err(_) => ptr::null_mut(),
    }
}

#[no_mangle]
pub extern "C" fn get_recommended_quality(input_size: usize) -> u32 {
    // Recommend quality based on input size for optimal speed/compression balance
    match input_size {
        0..=1024 => 4,        // Small data: fast compression
        1025..=10240 => 6,    // Medium data: balanced
        10241..=102400 => 8,  // Large data: better compression
        _ => 10,              // Very large data: maximum compression
    }
}

#[no_mangle]
pub extern "C" fn estimate_compressed_size(input_size: usize, quality: u32) -> usize {
    // Rough estimation of compressed size based on quality and input size
    let compression_ratio = match quality {
        0..=3 => 0.7,   // Low quality: ~30% compression
        4..=6 => 0.5,   // Medium quality: ~50% compression
        7..=9 => 0.4,   // High quality: ~60% compression
        _ => 0.3,       // Maximum quality: ~70% compression
    };
    
    ((input_size as f64) * compression_ratio) as usize
}

#[no_mangle]
pub extern "C" fn free_memory(ptr: *mut u8) {
    if !ptr.is_null() {
        unsafe {
            libc::free(ptr as *mut libc::c_void);
        }
    }
}

#[no_mangle]
pub extern "C" fn free_string(s: *mut c_char) {
    if !s.is_null() {
        unsafe {
            let _ = CString::from_raw(s);
        }
    }
}

#[no_mangle]
pub extern "C" fn get_version() -> *const c_char {
    static VERSION: &str = "1.0.0\0";
    VERSION.as_ptr() as *const c_char
}

#[no_mangle]
pub extern "C" fn benchmark_compression(
    input_data: *const u8,
    input_len: usize,
    iterations: u32,
) -> f64 {
    if input_data.is_null() || iterations == 0 {
        return -1.0;
    }

    let input_slice = unsafe { slice::from_raw_parts(input_data, input_len) };
    let start = std::time::Instant::now();
    
    for _ in 0..iterations {
        let _ = compress_internal(input_slice, 6, 22); // Use default settings
    }
    
    let duration = start.elapsed();
    duration.as_secs_f64() / iterations as f64
}

// Internal compression function
fn compress_internal(input: &[u8], quality: u32, window_size: u32) -> Result<Vec<u8>, std::io::Error> {
    let mut output = Vec::new();
    
    // Create compressor with custom parameters
    let params = brotli::enc::BrotliEncoderParams {
        quality: quality as i32,
        lgwin: window_size as i32,
        ..Default::default()
    };
    
    let mut compressor = CompressorReader::with_params(input, 4096, &params);
    compressor.read_to_end(&mut output)?;
    
    Ok(output)
}

// Internal decompression function
fn decompress_internal(input: &[u8]) -> Result<Vec<u8>, std::io::Error> {
    let mut output = Vec::new();
    let mut decompressor = DecompressorReader::new(input, 4096);
    decompressor.read_to_end(&mut output)?;
    Ok(output)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::ptr;

    #[test]
    fn test_compress_decompress() {
        let test_data = b"Hello, World! This is a test string for Brotli compression.";
        let mut output_len = 0;
        
        // Compress
        let compressed_ptr = compress(
            test_data.as_ptr(),
            test_data.len(),
            6,
            22,
            &mut output_len,
        );
        
        assert!(!compressed_ptr.is_null());
        assert!(output_len > 0);
        assert!(output_len < test_data.len()); // Should be smaller than original
        
        // Get compressed data
        let compressed_slice = unsafe { slice::from_raw_parts(compressed_ptr, output_len) };
        
        // Decompress
        let mut decompressed_len = 0;
        let decompressed_ptr = decompress(
            compressed_slice.as_ptr(),
            compressed_slice.len(),
            &mut decompressed_len,
        );
        
        assert!(!decompressed_ptr.is_null());
        assert_eq!(decompressed_len, test_data.len());
        
        // Verify data
        let decompressed_slice = unsafe { slice::from_raw_parts(decompressed_ptr, decompressed_len) };
        assert_eq!(decompressed_slice, test_data);
        
        // Cleanup
        free_memory(compressed_ptr);
        free_memory(decompressed_ptr);
    }

    #[test]
    fn test_string_compression() {
        let test_string = "This is a test string for Brotli compression!";
        let c_string = CString::new(test_string).unwrap();
        let mut output_len = 0;
        
        // Compress string
        let compressed_ptr = compress_string(
            c_string.as_ptr(),
            6,
            22,
            &mut output_len,
        );
        
        assert!(!compressed_ptr.is_null());
        assert!(output_len > 0);
        
        // Decompress to string
        let decompressed_cstring = decompress_to_string(compressed_ptr, output_len);
        assert!(!decompressed_cstring.is_null());
        
        let decompressed_str = unsafe { CStr::from_ptr(decompressed_cstring).to_str().unwrap() };
        assert_eq!(decompressed_str, test_string);
        
        // Cleanup
        free_memory(compressed_ptr);
        free_string(decompressed_cstring);
    }

    #[test]
    fn test_quality_recommendation() {
        assert_eq!(get_recommended_quality(500), 4);
        assert_eq!(get_recommended_quality(5000), 6);
        assert_eq!(get_recommended_quality(50000), 8);
        assert_eq!(get_recommended_quality(500000), 10);
    }

    #[test]
    fn test_size_estimation() {
        let size = estimate_compressed_size(1000, 6);
        assert!(size > 0);
        assert!(size <= 1000); // Should be smaller than or equal to input
    }

    #[test]
    fn test_benchmark() {
        let test_data = vec![42u8; 1024];
        let duration = benchmark_compression(test_data.as_ptr(), test_data.len(), 10);
        assert!(duration > 0.0);
        assert!(duration < 1.0); // Should complete in less than 1 second per iteration
    }

    #[test]
    fn test_different_qualities() {
        let test_data = b"A".repeat(1000);
        
        for quality in [1, 6, 11] {
            let mut output_len = 0;
            let compressed_ptr = compress(
                test_data.as_ptr(),
                test_data.len(),
                quality,
                22,
                &mut output_len,
            );
            
            assert!(!compressed_ptr.is_null());
            
            // Higher quality should generally produce smaller output
            println!("Quality {}: {} bytes", quality, output_len);
            
            free_memory(compressed_ptr);
        }
    }
}