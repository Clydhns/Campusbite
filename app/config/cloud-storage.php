<?php
function cbStoragePath($path): string {
    return is_string($path) && preg_match('~^uploads/(foods|payments|receipts|avatars)/[a-f0-9]{32}\.(jpg|png|webp)$~D',$path) ? $path : '';
}
// Receipts and profile photos are private: they never get a public URL.
function cbPrivateStoragePath(string $relative): bool {
    return str_starts_with($relative, 'uploads/receipts/') || str_starts_with($relative, 'uploads/avatars/');
}
function cbPublicImageUrl(string $path): string {
    $relative = cbStoragePath($path);
    if (!$relative || cbPrivateStoragePath($relative)) return '';
    $url = rtrim(getenv('SUPABASE_URL') ?: '', '/');
    if (!preg_match('~^https://[a-z0-9-]+\.supabase\.co$~D', $url)) return $relative;
    return $url . '/storage/v1/object/public/campusbite-images/' . substr($relative, 8);
}
function cbStorageConfig(): array {
    $url = rtrim(getenv('SUPABASE_URL') ?: '', '/');
    $secret = getenv('SUPABASE_SECRET_KEY') ?: '';
    if (!preg_match('~^https://[a-z0-9-]+\.supabase\.co$~D',$url) || !str_starts_with($secret,'sb_secret_')) {
        throw new RuntimeException('Cloud image storage is not configured.');
    }
    return [$url,$secret];
}
// Local development only (tools/dev-server.mjs): keep objects on disk instead of Supabase Storage.
// Requires CAMPUSBITE_LOCAL_TEST, which must never be set on Vercel.
function cbLocalStorageDir(): string {
    $dir = getenv('CAMPUSBITE_LOCAL_STORAGE_DIR') ?: '';
    return getenv('CAMPUSBITE_LOCAL_TEST') === '1' && $dir !== '' && is_dir($dir) ? rtrim($dir, '/\\') : '';
}
function cbLocalStorageRequest(string $dir,string $method,string $relative,?string $data): array {
    $file=$dir.'/'.$relative;
    if ($method==='GET') {
        $body=is_file($file) ? file_get_contents($file) : false;
        if ($body===false) throw new RuntimeException('Unable to access local image storage.');
        return ['status'=>200,'body'=>$body];
    }
    if ($method==='DELETE') {
        if (!is_file($file)) return ['status'=>404,'body'=>''];
        unlink($file);
        return ['status'=>200,'body'=>''];
    }
    if ($method!=='POST' || $data===null || is_file($file)) throw new RuntimeException('Unable to access local image storage.');
    if (!is_dir(dirname($file))) mkdir(dirname($file),0700,true);
    if (file_put_contents($file,$data,LOCK_EX)===false) throw new RuntimeException('Unable to access local image storage.');
    return ['status'=>200,'body'=>''];
}
function cbStorageRequest(string $method,string $path,?string $data=null,?string $mime=null): array {
    $relative=cbStoragePath($path);
    if (!$relative) throw new RuntimeException('Invalid image path.');
    if ($local=cbLocalStorageDir()) return cbLocalStorageRequest($local,$method,$relative,$data);
    [$url,$secret]=cbStorageConfig();
    $parts=explode('/',$relative);
    $bucket=in_array($parts[1],['receipts','avatars'],true) ? 'campusbite-receipts' : 'campusbite-images';
    $object=$parts[1].'/'.$parts[2];
    $headers=['apikey: '.$secret,'User-Agent: CampusBite-Server/1.0'];
    if ($mime!==null) $headers[]='Content-Type: '.$mime;
    if ($method==='POST') $headers[]='x-upsert: false';
    $request=curl_init($url.'/storage/v1/object/'.$bucket.'/'.$object);
    curl_setopt_array($request,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false]);
    if ($data!==null) curl_setopt($request,CURLOPT_POSTFIELDS,$data);
    $body=curl_exec($request);
    $status=(int)curl_getinfo($request,CURLINFO_HTTP_CODE);
    curl_close($request);
    if ($body===false || $status<200 || $status>=300) {
        if ($method==='DELETE' && $status===404) return ['status'=>404,'body'=>''];
        error_log('CampusBite storage request failed: '.$method.' HTTP '.$status);
        throw new RuntimeException('Unable to access cloud image storage. Please try again.');
    }
    return ['status'=>$status,'body'=>$body];
}
function cbStorageUpload(string $kind,array $file,string $extension): string {
    if (!in_array($kind,['foods','payments','receipts','avatars'],true)
        || !in_array($extension,['jpg','png','webp'],true) || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('Invalid image upload.');
    }
    $path='uploads/'.$kind.'/'.bin2hex(random_bytes(16)).'.'.$extension;
    $mime=['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$extension];
    $data=file_get_contents($file['tmp_name']);
    if ($data===false || strlen($data)>3*1024*1024) throw new RuntimeException('Choose an image up to 3 MB.');
    cbStorageRequest('POST',$path,$data,$mime);
    return $path;
}
function cbStorageDelete($path): void {
    if (!cbStoragePath($path)) return;
    try { cbStorageRequest('DELETE',$path); }
    catch (Throwable $error) { error_log('Cloud image cleanup needs retry: '.$path); }
}
