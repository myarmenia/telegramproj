<?php

namespace App\Services\Product;

use App\Models\Image;
use App\Models\Video;
use App\Services\FileUploadService;
use Illuminate\Support\Facades\Http;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use DefStudio\Telegraph\Facades\Telegraph;
use GeminiAPI\Client;
use GeminiAPI\Resources\Parts\TextPart;
use Stichoza\GoogleTranslate\GoogleTranslate;



class ProductService
{

    public function addProduct()
    {
        try {
            DB::beginTransaction();
            $botToken = env('TELEGRAM_BOT_TOKEN');
            $last = Product::orderBy('id', 'desc')->first();
            $lastUpdate = $last ? $last->update_id : 0;

            $response = Http::post('https://api.telegram.org/bot' . $botToken . '/getUpdates', [
                'offset' => $lastUpdate + 1
            ]);

            $results = $response->json()['result'];

            foreach ($results as $key => $result) {
    //   dd($result);
                $resultMessage = $result['message'];
                if(isset($resultMessage['text']) || isset($resultMessage['caption'])){
                    try {
                        $key = isset($resultMessage['text']) ? 'text' : 'caption';

                        //Start Gemini
                        $geminiSendedText = $this->setGemini($resultMessage[$key]);
                        $start = strpos($geminiSendedText, '{');
                        $end = strpos($geminiSendedText, '}')+1;
                        $readyText = null;

                        if ($start !== false && $end !== false) {
                            $found_text = substr($geminiSendedText, $start, $end - $start );
                            $tmp = json_decode($found_text, true);
    
                            if(
                                !array_key_exists('title', $tmp) ||
                                !array_key_exists('price_in_store', $tmp) ||
                                !array_key_exists('owner', $tmp) ||
                                !array_key_exists('cashback', $tmp) 
                            ){
                                continue;
                            }

                            $tmp['owner'] = str_replace('@', '', $tmp['owner']);
                            $tmp['title_am'] = GoogleTranslate::trans($tmp['title'], 'hy', 'ru');
                            $tmp['price_in_store'] = (int) str_replace(' ', '', $tmp['price_in_store']);
    
                            if(!str_contains($tmp['cashback'], '%')){
                                $tmp['cashback'] = round(((int) $tmp['cashback'] * 100) / (int) $tmp['price_in_store']);
                            }else{
                                $tmp['cashback'] = (int) $tmp['cashback'];
                            }
                          
                            $readyText = json_encode($tmp, JSON_UNESCAPED_UNICODE);
                        } else{
                            continue;
                        }
    
                        $product = Product::create([
                            'text' => json_encode($resultMessage[$key], JSON_UNESCAPED_UNICODE),
                            'update_id' => $result['update_id'],
                            'media_group_id' => isset($resultMessage['media_group_id']) ? $resultMessage['media_group_id'] : null,
                            'product_details' => $readyText
                        ]);

                        //End Gemini
                    } catch (\Throwable $th) {
                        continue;
                    }
                    
                }else{
                    $product = Product::where('media_group_id', $resultMessage['media_group_id'])->first();
                }
                
                if(!$product){
                    continue;
                }

                if (isset($resultMessage['photo'])) {

                    $response = Telegraph::getFileInfo($resultMessage['photo'][2]['file_id'])->send();
                    $filePath = $response->json()['result']['file_path'];

                    $downloadUrl = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
                    $fileContent = Http::get($downloadUrl)->body();
                    $photo = FileUploadService::upload($fileContent, basename($filePath), "telegram/$product->id");

                    if ($photo['success']) {
                        Image::create(['product_id' => $product->id, 'path' => $photo['path']]);
                    }
                }

                if(isset($resultMessage['video'])){
                    $response = Telegraph::getFileInfo($resultMessage['video']['file_id'])->send();
                    $filePath = $response->json()['result']['file_path'];

                    $downloadUrl = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
                    $fileContent = Http::get($downloadUrl)->body();
                    $photo = FileUploadService::upload($fileContent, basename($filePath), "telegram/$product->id");

                    if ($photo['success']) {
                        Video::create(['product_id' => $product->id, 'path' => $photo['path']]);
                    }
                }
            }
            DB::commit();
                return true;
        } catch (\Exception $e) {
            info("Exception", [$e->getMessage()]);
            DB::rollBack();
            dd($e->getMessage());
        } catch (\Error $e) {
            info("error", [$e->getMessage()]);
            DB::rollBack();
            dd($e->getMessage());
        }

    }

    public function setGemini($text)
    {
        $text = "find the title with the key 'title', price in store  with the key 'price_in_store', Agree on the buyout with with the key 'owner',  cashback with the key 'cashback', in this text and send it to me in JSON format. '$text'";
        $client = new Client(env('GEMINI_API_KEY'));
        $response = $client->geminiPro()->generateContent(
            new TextPart($text),
        );

        return $response->text();
    }

}