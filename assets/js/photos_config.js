export let getBlobFromImageUri = image_uri =>{
  //https://github.com/whatwg/fetch/issues/299
  //https://www.codingepiphany.com/2017/03/20/es6-fetch-always-returns-response-ok-false/
  //https://stackoverflow.com/questions/42342452/strange-error-when-including-no-cors-into-header-for-fetch-of-json-content-in-a
  //https://stackoverflow.com/questions/35169728/redux-fetch-body-is-not-use-with-no-cors-mode/35291777#35291777
  //https://fetch.spec.whatwg.org/#concept-filtered-response-opaque
  //if (res.type === 'opaque' || res.ok){
  return new Promise((resolve, reject)=>{
    fetch(image_uri)
      .then(res=>{
        if (res.ok){
          return res.blob();
        } else {
          throw Error();
        }
      })
      .then(res=>{
        resolve(res);
      })
      .catch(e=>{
        // let errBlob = createErrorImgPlaceHolder();
        // resolve(errBlob);
        console.log(e);
        reject(false);
      })
  })
};

export let delay = ms =>{
    return new Promise((resolve, reject)=>{
        setTimeout(resolve, ms);
    });
};

export function createErrorImgPlaceHolder(){
    let canvas = document.createElement("canvas");
    let ctx = canvas.getContext("2d");


    //размер плейсхолдера в Uppy (если изменить размер браузера)
    canvas.width = 140;
    canvas.height = 100;


    ctx.fillStyle = "white";
    ctx.fillRect(0, 0, canvas.width, canvas.height);


    //рандомный цвет обводки, чтобы на выходе был рандомный blob (если их несколько)
    //иначе Uppy некорректно добавляет фото в Dashboard
    ctx.strokeStyle = '#'+Math.floor(Math.random()*16777215).toString(16);
    ctx.strokeRect(0, 0, canvas.width, canvas.height);


    ctx.font = '20px Source Sans Pro';
    ctx.fillStyle = '#333';
    ctx.textAlign = "center";
    ctx.fillText('Img', canvas.width/2, 40);
    ctx.fillText('not exist', canvas.width/2, 70);


    return new Promise((resolve,reject)=>{
        canvas.toBlob(function(blob){
            resolve(blob);
        });
    });
};
