#include "SpikiaSignWebSocketClient.h"

#include "WebSocketsModule.h"
#include "Components/SkeletalMeshComponent.h"
#include "Animation/AnimInstance.h"
#include "Animation/AnimSequenceBase.h"
#include "Dom/JsonObject.h"
#include "Serialization/JsonReader.h"
#include "Serialization/JsonSerializer.h"
#include "Serialization/JsonWriter.h"
#include "TimerManager.h"
#include "Engine/World.h"

USpikiaSignWebSocketClient::USpikiaSignWebSocketClient()
{
	// Este componente es 100% event-driven (mensajes de WebSocket + un timer de reconexion),
	// no necesita Tick.
	PrimaryComponentTick.bCanEverTick = false;
}

void USpikiaSignWebSocketClient::BeginPlay()
{
	Super::BeginPlay();

	if (bAutoConnect)
	{
		Connect();
	}
}

void USpikiaSignWebSocketClient::EndPlay(const EEndPlayReason::Type EndPlayReason)
{
	Disconnect();
	Super::EndPlay(EndPlayReason);
}

void USpikiaSignWebSocketClient::Connect()
{
	if (WebSocket.IsValid() && WebSocket->IsConnected())
	{
		return;
	}

	if (SessionId.IsEmpty())
	{
		UE_LOG(LogTemp, Error, TEXT("[SpikiaSignAvatar] No se puede conectar: SessionId esta vacio."));
		return;
	}

	bShuttingDown = false;

	// Protocolo vacio: la autenticacion no va en el handshake HTTP, sino DENTRO del primer
	// mensaje que se manda apenas conecta (ver SendRegisterMessage) - mas simple de
	// inspeccionar con herramientas genericas de WebSocket al debuggear.
	WebSocket = FWebSocketsModule::Get().CreateWebSocket(ServerUrl, FString());

	WebSocket->OnConnected().AddUObject(this, &USpikiaSignWebSocketClient::HandleConnected);
	WebSocket->OnConnectionError().AddUObject(this, &USpikiaSignWebSocketClient::HandleConnectionError);
	WebSocket->OnClosed().AddUObject(this, &USpikiaSignWebSocketClient::HandleClosed);
	WebSocket->OnMessage().AddUObject(this, &USpikiaSignWebSocketClient::HandleMessage);

	WebSocket->Connect();
}

void USpikiaSignWebSocketClient::Disconnect()
{
	bShuttingDown = true;

	if (UWorld* World = GetWorld())
	{
		World->GetTimerManager().ClearTimer(ReconnectTimerHandle);
	}

	if (WebSocket.IsValid())
	{
		WebSocket->OnConnected().RemoveAll(this);
		WebSocket->OnConnectionError().RemoveAll(this);
		WebSocket->OnClosed().RemoveAll(this);
		WebSocket->OnMessage().RemoveAll(this);

		if (WebSocket->IsConnected())
		{
			WebSocket->Close();
		}

		WebSocket.Reset();
	}
}

bool USpikiaSignWebSocketClient::IsConnected() const
{
	return WebSocket.IsValid() && WebSocket->IsConnected();
}

void USpikiaSignWebSocketClient::HandleConnected()
{
	UE_LOG(LogTemp, Log, TEXT("[SpikiaSignAvatar] Conectado al orquestador (%s)."), *ServerUrl);
	OnConnectedToOrchestrator.Broadcast();
	SendRegisterMessage();
}

void USpikiaSignWebSocketClient::HandleConnectionError(const FString& Error)
{
	UE_LOG(LogTemp, Warning, TEXT("[SpikiaSignAvatar] Error de conexion con el orquestador: %s"), *Error);
	ScheduleReconnect();
}

void USpikiaSignWebSocketClient::HandleClosed(int32 StatusCode, const FString& Reason, bool bWasClean)
{
	UE_LOG(LogTemp, Warning, TEXT("[SpikiaSignAvatar] Conexion con el orquestador cerrada (codigo %d, limpia: %s, razon: %s)."),
		StatusCode, bWasClean ? TEXT("si") : TEXT("no"), *Reason);

	OnDisconnectedFromOrchestrator.Broadcast();

	if (!bShuttingDown)
	{
		ScheduleReconnect();
	}
}

void USpikiaSignWebSocketClient::ScheduleReconnect()
{
	if (bShuttingDown)
	{
		return;
	}

	UWorld* World = GetWorld();
	if (!World)
	{
		return;
	}

	World->GetTimerManager().SetTimer(
		ReconnectTimerHandle,
		this,
		&USpikiaSignWebSocketClient::Connect,
		ReconnectDelaySeconds,
		/*bLoop=*/ false
	);
}

void USpikiaSignWebSocketClient::SendRegisterMessage()
{
	if (!WebSocket.IsValid() || !WebSocket->IsConnected())
	{
		return;
	}

	// { "type": "register", "sessionId": "<slug de la sesion>", "token": "<UNREAL_AUTH_TOKEN>" }
	// El orquestador (sign-avatar-orchestrator/index.js) usa este mensaje para saber a que
	// sesion de Spikia enrutar las secuencias de glosas que Laravel le publique despues.
	const TSharedRef<FJsonObject> JsonObject = MakeShared<FJsonObject>();
	JsonObject->SetStringField(TEXT("type"), TEXT("register"));
	JsonObject->SetStringField(TEXT("sessionId"), SessionId);
	JsonObject->SetStringField(TEXT("token"), AuthToken);

	FString Payload;
	const TSharedRef<TJsonWriter<>> Writer = TJsonWriterFactory<>::Create(&Payload);
	FJsonSerializer::Serialize(JsonObject, Writer);

	WebSocket->Send(Payload);
}

void USpikiaSignWebSocketClient::HandleMessage(const FString& MessageJson)
{
	TSharedPtr<FJsonObject> JsonObject;
	const TSharedRef<TJsonReader<>> Reader = TJsonReaderFactory<>::Create(MessageJson);

	if (!FJsonSerializer::Deserialize(Reader, JsonObject) || !JsonObject.IsValid())
	{
		UE_LOG(LogTemp, Warning, TEXT("[SpikiaSignAvatar] Se recibio un mensaje que no es JSON valido, se descarta."));
		return;
	}

	FString MessageType;
	JsonObject->TryGetStringField(TEXT("type"), MessageType);

	if (MessageType == TEXT("registered"))
	{
		UE_LOG(LogTemp, Log, TEXT("[SpikiaSignAvatar] Registro confirmado por el orquestador para la sesion '%s'."), *SessionId);
		return;
	}

	if (MessageType != TEXT("sign_frame"))
	{
		// Tipo de mensaje desconocido (o de una version mas nueva del orquestador) - se
		// ignora en vez de romper, para que el plugin viejo siga funcionando si el
		// orquestador agrega mensajes nuevos en el futuro.
		return;
	}

	FString Gloss;
	FString AnimationAssetName;
	JsonObject->TryGetStringField(TEXT("gloss"), Gloss);
	JsonObject->TryGetStringField(TEXT("animation"), AnimationAssetName);

	if (AnimationAssetName.IsEmpty())
	{
		UE_LOG(LogTemp, Warning, TEXT("[SpikiaSignAvatar] sign_frame sin campo 'animation', se descarta (gloss='%s')."), *Gloss);
		return;
	}

	OnSignFrameReceived.Broadcast(Gloss);
	PlaySignAnimation(AnimationAssetName);
}

UAnimInstance* USpikiaSignWebSocketClient::ResolveAnimInstance() const
{
	USkeletalMeshComponent* SkeletalMesh = TargetSkeletalMeshComponent;

	if (!SkeletalMesh)
	{
		if (const AActor* Owner = GetOwner())
		{
			// Fallback: si no se asigno TargetSkeletalMeshComponent a mano, se busca el
			// primer SkeletalMeshComponent del Actor duenio (el caso comun cuando este
			// componente se agrega directamente al Actor del MetaHuman).
			SkeletalMesh = Owner->FindComponentByClass<USkeletalMeshComponent>();
		}
	}

	return SkeletalMesh ? SkeletalMesh->GetAnimInstance() : nullptr;
}

void USpikiaSignWebSocketClient::PlaySignAnimation(const FString& AnimationAssetName, float BlendInOverride, float BlendOutOverride)
{
	UAnimInstance* AnimInstance = ResolveAnimInstance();
	if (!AnimInstance)
	{
		UE_LOG(LogTemp, Warning, TEXT("[SpikiaSignAvatar] No se encontro un AnimInstance valido - se descarta la seña '%s'. ")
			TEXT("Revisar que el MetaHuman tenga un SkeletalMeshComponent con AnimBlueprint asignado."), *AnimationAssetName);
		return;
	}

	// Path completo del asset: "/Game/Animations/Signs/Sign_Hola.Sign_Hola". LoadObject con
	// el path completo (paquete + nombre de objeto) es el patron correcto para cargar un
	// asset EN RUNTIME a partir de un string que solo conocemos en tiempo de ejecucion (acá,
	// el nombre que manda el orquestador) - a diferencia de ConstructorHelpers::FObjectFinder,
	// que solo sirve para referencias fijas resueltas en tiempo de compilacion.
	const FString PackagePath = FString::Printf(TEXT("%s/%s"), *AnimationAssetBasePath, *AnimationAssetName);
	const FString FullObjectPath = FString::Printf(TEXT("%s.%s"), *PackagePath, *AnimationAssetName);

	UAnimSequenceBase* AnimAsset = LoadObject<UAnimSequenceBase>(nullptr, *FullObjectPath);
	if (!AnimAsset)
	{
		UE_LOG(LogTemp, Warning, TEXT("[SpikiaSignAvatar] No se encontro el asset de animacion '%s'. ")
			TEXT("Verificar que exista en el Content Browser con ese nombre exacto."), *FullObjectPath);
		return;
	}

	const float BlendIn = BlendInOverride >= 0.f ? BlendInOverride : BlendInSeconds;
	const float BlendOut = BlendOutOverride >= 0.f ? BlendOutOverride : BlendOutSeconds;

	// PlaySlotAnimationAsDynamicMontage: arma (o reusa) un UAnimMontage dinamico para el Slot
	// indicado sin necesitar un UAnimMontage preconfigurado a mano por cada seña en el editor
	// - fundamental aca porque el catalogo de señas puede crecer solo agregando el
	// UAnimSequence correspondiente, sin tocar nada mas del lado de Unreal Engine.
	AnimInstance->PlaySlotAnimationAsDynamicMontage(
		AnimAsset,
		AnimationSlotName,
		BlendIn,
		BlendOut,
		/*InPlayRate=*/ 1.0f,
		/*LoopCount=*/ 1,
		/*BlendOutTriggerTime=*/ -1.0f,
		/*InTimeToStartMontageAt=*/ 0.0f
	);
}
