<?php
namespace MMAF\DataEngine\Services;

/** Deterministic suggestions. Every result still requires an administrator's decision. */
final class UfcMatcher {
    public const VERSION = '2';

    public static function normalize(string $name): string {
        $name=remove_accents(mb_strtolower(trim($name)));
        return trim(preg_replace('/\s+/u',' ',preg_replace('/[^\pL\pN]+/u',' ',$name)));
    }

    private static function latin(string $name): string {
        if(function_exists('transliterator_transliterate')){
            $converted=transliterator_transliterate('Any-Latin; Latin-ASCII',$name);
            if(is_string($converted)){$name=$converted;}
        }
        return self::normalize($name);
    }

    private static function variants(string $name): array {
        $latin=self::latin($name);$tokens=explode(' ',$latin);$variants=[$latin];
        if(count($tokens)>1){
            $surname=array_pop($tokens);$initials=array_map(static fn($token)=>mb_substr($token,0,1),$tokens);
            $variants[]=implode(' ',$initials).' '.$surname;
            $variants[]=implode('',$initials).' '.$surname;
            $tokens[]=$surname;
        }
        if(count($tokens)>2){
            $i=0;$initials='';while(isset($tokens[$i]) && mb_strlen($tokens[$i])===1){$initials.=$tokens[$i];++$i;}
            if($i>1 && isset($tokens[$i])){$variants[]=implode(' ',array_merge([$initials],array_slice($tokens,$i)));}
        }
        if(count($tokens)>1){$sorted=$tokens;sort($sorted,SORT_STRING);$variants[]=implode(' ',$sorted);}
        return array_values(array_unique(array_filter($variants)));
    }

    public static function build_index(array $fighters,array $aliases,array $links=[]): array {
        $index=['fighters'=>[],'exact'=>[],'variant'=>[],'dob'=>[],'links'=>[]];
        foreach($fighters as $fighter){
            if(!empty($fighter['deleted_soft'])){continue;}
            $id=(int)$fighter['id'];$fighter['match_names']=[$fighter['display_name']];$index['fighters'][$id]=$fighter;
        }
        foreach($aliases as $alias){$id=(int)$alias['fighter_id'];if(isset($index['fighters'][$id])){$index['fighters'][$id]['match_names'][]=(string)$alias['alias'];}}
        foreach($index['fighters'] as $id=>&$fighter){
            $fighter['match_names']=array_values(array_unique(array_filter($fighter['match_names'])));
            $canonical=array_shift($fighter['match_names']);sort($fighter['match_names'],SORT_STRING);array_unshift($fighter['match_names'],$canonical);
            $fighter['identity_hash']=hash('sha256',wp_json_encode([$fighter['display_name'],$fighter['date_of_birth']??null,$fighter['gender']??null,$fighter['weight_class']??null,$fighter['match_names']],JSON_UNESCAPED_UNICODE));
            foreach($fighter['match_names'] as $position=>$name){
                $exact=self::normalize($name);if($exact){$index['exact'][$exact][$id]=$position?'alias':'canonical_name';}
                foreach(self::variants($name) as $variant){$index['variant'][$variant][$id]=$position?'alias_variant':'name_variant';}
            }
            if(!empty($fighter['date_of_birth'])){$index['dob'][$fighter['date_of_birth']][$id]=true;}
        }unset($fighter);
        foreach($links as $link){$index['links'][$link['source_key']][(int)$link['fighter_id']]=$link['status'];}
        return $index;
    }

    public static function match(array $source,array $index): array {
        $name=self::normalize((string)($source['display_name']??''));if(!$name){return [];}
        $key=(string)($source['source_key']??'');$sourceDob=(string)($source['date_of_birth']??'');
        $found=[];
        foreach(($index['links'][$key]??[]) as $id=>$status){if($status==='verified' && isset($index['fighters'][$id])){$found[$id]='verified_link';}}
        foreach(($index['exact'][$name]??[]) as $id=>$reason){$found[$id]??=$reason;}
        foreach(self::variants((string)$source['display_name']) as $variant){foreach(($index['variant'][$variant]??[]) as $id=>$reason){$found[$id]??=$reason;}}
        if($sourceDob && isset($index['dob'][$sourceDob])){
            $sourceLatin=self::latin((string)$source['display_name']);$sourceTokens=array_filter(explode(' ',$sourceLatin),static fn($token)=>mb_strlen($token)>=3);
            foreach($index['dob'][$sourceDob] as $id=>$_){
                if(isset($found[$id])){continue;}
                foreach($index['fighters'][$id]['match_names'] as $candidate){
                    $other=self::latin($candidate);$length=max(strlen($sourceLatin),strlen($other));
                    if(!$length || !array_intersect($sourceTokens,array_filter(explode(' ',$other),static fn($token)=>mb_strlen($token)>=3))){continue;}
                    if(levenshtein($sourceLatin,$other)<=2 && 1-levenshtein($sourceLatin,$other)/$length>=0.9){$found[$id]='similar_name_and_date_of_birth';break;}
                }
            }
        }
        $out=[];$multiple=count($found)>1;
        foreach($found as $id=>$reason){
            if(($index['links'][$key][$id]??'')==='different'){continue;}
            $fighter=$index['fighters'][$id];$conflicts=[];$signals=[];$fighterDob=(string)($fighter['date_of_birth']??'');
            if($sourceDob && $fighterDob){
                if($sourceDob===$fighterDob && in_array($reason,['canonical_name','alias'],true)){$reason='name_and_date_of_birth';}
                elseif($sourceDob!==$fighterDob){$conflicts[]=['type'=>'date_of_birth','source'=>$sourceDob,'fighter'=>$fighterDob];}
            }
            $sourceGender=(string)($source['gender']??'');$fighterGender=(string)($fighter['gender']??'');
            if($sourceGender && $fighterGender && $sourceGender!==$fighterGender){$conflicts[]=['type'=>'gender','source'=>$sourceGender,'fighter'=>$fighterGender];}
            $sourceWeight=self::normalize((string)($source['weight_class']??''));$fighterWeight=self::normalize((string)($fighter['weight_class']??''));
            if($sourceWeight && $fighterWeight){$signals[]=['type'=>$sourceWeight===$fighterWeight?'weight_class_agrees':'weight_class_changed','source'=>$source['weight_class'],'fighter'=>$fighter['weight_class']];}
            if($multiple){$conflicts[]=['type'=>'multiple_name_matches','count'=>count($found)];}
            $out[]=['fighter_id'=>(int)$id,'reason'=>$reason,'conflicts'=>$conflicts,'signals'=>$signals,'fighter_hash'=>$fighter['identity_hash'],'already_confirmed'=>!empty($fighter['in_ufc'])];
        }
        return $out;
    }
}
